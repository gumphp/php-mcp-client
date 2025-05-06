<?php

declare(strict_types=1);

namespace PhpMcp\Client;

use PhpMcp\Client\Enum\ConnectionStatus;
use PhpMcp\Client\Exception\ConfigurationException;
use PhpMcp\Client\Exception\ConnectionException;
use PhpMcp\Client\Exception\McpClientException;
use PhpMcp\Client\Exception\RequestException;
use PhpMcp\Client\Exception\TimeoutException;
use PhpMcp\Client\Factory\TransportFactory;
use PhpMcp\Client\JsonRpc\Request;
use PhpMcp\Client\JsonRpc\Response;
use PhpMcp\Client\Transport\Internal\PromiseAwaiter;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Manages multiple ServerConnection instances and orchestrates
 * the blocking facade logic.
 *
 * @internal This class is primarily for internal use by the Client facade.
 */
final class ConnectionManager
{
    private LoopInterface $loop;

    private LoggerInterface $logger;

    /** @var array<string, ServerConfig> */
    private array $serverConfigs = [];

    /** @var array<string, ServerConnection> */
    private array $connections = [];

    /** @var array<string, PromiseInterface> Promises for ongoing connect attempts */
    private array $connectingPromises = [];

    /**
     * @param  array<string, ServerConfig>  $serverConfigs
     */
    public function __construct(
        array $serverConfigs,
        private readonly ClientConfig $clientConfig,
        private ?TransportFactory $transportFactory = null
    ) {
        $this->loop = $clientConfig->loop;
        $this->logger = $clientConfig->logger;
        $this->transportFactory = $transportFactory ?? new TransportFactory($clientConfig);

        foreach ($serverConfigs as $name => $config) {
            if (! $config instanceof ServerConfig) {
                throw new ConfigurationException("Invalid configuration provided for server '{$name}'. Must be ServerConfig instance.");
            }
            if ($name !== $config->name) {
                $this->logger->warning("Server configuration key '{$name}' differs from name '{$config->name}' in config object. Using key '{$name}'.");
            }
            $this->serverConfigs[$name] = $config;
        }
    }

    /**
     * Ensures a connection is established and ready, blocking until completion.
     *
     * @throws ConnectionException|TimeoutException|ConfigurationException|Throwable
     */
    public function ensureConnected(string $serverName): ServerConnection
    {
        if (! isset($this->serverConfigs[$serverName])) {
            throw new ConfigurationException("Server '{$serverName}' is not configured.");
        }

        // Return existing ready connection immediately
        if (isset($this->connections[$serverName])) {
            $conn = $this->connections[$serverName];
            $connStatus = $conn->getStatus();
            if ($connStatus === ConnectionStatus::Ready) {
                return $conn;
            }
            if ($connStatus !== ConnectionStatus::Disconnected && $connStatus !== ConnectionStatus::Closed) {
                throw new ConnectionException("Cannot use server '{$serverName}', connection is in unstable state: {$connStatus->value}");
            }
        }

        // If already connecting, wait for the existing attempt's promise
        if (isset($this->connectingPromises[$serverName])) {
            $this->logger->debug("Waiting for existing connection attempt to '{$serverName}'...");
            $connectPromise = $this->connectingPromises[$serverName];
            $waitTimeout = $this->serverConfigs[$serverName]->timeout;

            try {
                PromiseAwaiter::await($connectPromise, $waitTimeout, $this->loop, "Connection attempt for '{$serverName}'");

                unset($this->connectingPromises[$serverName]);

                if (isset($this->connections[$serverName]) && $this->connections[$serverName]->getStatus() === ConnectionStatus::Ready) {
                    return $this->connections[$serverName];
                } else {
                    throw new ConnectionException("Existing connection attempt for '{$serverName}' resolved unexpectedly (not Ready).");
                }
            } catch (Throwable $e) {
                unset($this->connectingPromises[$serverName]);

                if (isset($this->connections[$serverName])) {
                    $this->connections[$serverName]->handleConnectionFailure($e);
                }

                if ($e instanceof ConnectionException || $e instanceof TimeoutException) {
                    throw $e;
                }

                throw new ConnectionException("Existing connection attempt for '{$serverName}' failed: {$e->getMessage()}", 0, $e);
            }
        }

        $this->logger->debug("Initiating new connection to '{$serverName}'...");

        $connection = new ServerConnection(
            $this->serverConfigs[$serverName],
            $this->clientConfig,
            $this->transportFactory
        );
        $this->connections[$serverName] = $connection;

        $connectPromise = $connection->connectAsync();
        $this->connectingPromises[$serverName] = $connectPromise;

        try {
            $connectTimeout = $this->serverConfigs[$serverName]->timeout + 2;

            PromiseAwaiter::await($connectPromise, $connectTimeout, $this->loop, "Connection attempt for '{$serverName}'");

            unset($this->connectingPromises[$serverName]);

            if ($connection->getStatus() !== ConnectionStatus::Ready) {
                throw new ConnectionException("Connection to '{$serverName}' completed but status is not Ready ({$connection->getStatus()->value}).");
            }

            return $connection;
        } catch (Throwable $e) {
            unset($this->connectingPromises[$serverName]);
            $this->logger->error("Connection attempt failed for '{$serverName}': {$e->getMessage()}");

            if ($connection->getStatus() !== ConnectionStatus::Error && $connection->getStatus() !== ConnectionStatus::Closed) {
                $connection->handleConnectionFailure($e);
            }

            if ($e instanceof ConnectionException || $e instanceof TimeoutException) {
                throw $e;
            }

            throw new ConnectionException("Failed to connect to server '{$serverName}': {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Sends a request and blocks until a response is received or timeout occurs.
     *
     * @throws ConnectionException|TimeoutException|RequestException|McpClientException|Throwable
     */
    public function sendRequestAndWait(string $serverName, Request $request, ?float $timeout = null): Response
    {
        $connection = $this->ensureConnected($serverName);

        $waitTimeout = $timeout ?? $connection->serverConfig->timeout;

        try {
            $responsePromise = $connection->sendAsync($request);

            $response = PromiseAwaiter::await($responsePromise, $waitTimeout, $this->loop, "Request '{$request->method}'");

            if (! $response instanceof Response) {
                throw new McpClientException('Internal error: sendAsync did not resolve with a Response object or otherwise handler failed.');
            }

            return $response;

        } catch (Throwable $e) {
            if ($e instanceof McpClientException) {
                throw $e;
            }

            throw new McpClientException("Unexpected error waiting for request '{$request->method}' (ID: {$request->id}): {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Disconnects a specific server connection, blocking until complete.
     */
    public function disconnect(string $serverName): void
    {
        if (! isset($this->connections[$serverName])) {
            $this->logger->debug("Attempted to disconnect server '{$serverName}', but no connection object found.");
            unset($this->connectingPromises[$serverName]);

            return;
        }

        $connection = $this->connections[$serverName];

        if ($connection->getStatus() === ConnectionStatus::Closing || $connection->getStatus() === ConnectionStatus::Closed) {
            unset($this->connections[$serverName]);
            unset($this->connectingPromises[$serverName]);

            return;
        }

        $disconnectPromise = $connection->disconnectAsync();

        try {
            PromiseAwaiter::await($disconnectPromise, 5.0, $this->loop, "Disconnect from '{$serverName}'");
            $this->logger->info("Successfully disconnected from '{$serverName}'.");
        } catch (Throwable $e) {
            $this->logger->error("Error during disconnect from '{$serverName}': {$e->getMessage()}", ['exception' => $e]);
        } finally {
            unset($this->connections[$serverName]);
            unset($this->connectingPromises[$serverName]);
        }
    }

    /**
     * Disconnects all active server connections, blocking until complete.
     */
    public function disconnectAll(): void
    {
        $serverNames = array_keys($this->connections);
        if (empty($serverNames)) {
            $this->logger->info('DisconnectAll: No active connections to close.');

            return;
        }
        $this->logger->info('Disconnecting all servers: '.implode(', ', $serverNames));

        $disconnectPromises = [];
        foreach ($serverNames as $serverName) {
            if (isset($this->connections[$serverName])) {
                $connection = $this->connections[$serverName];
                if ($connection->getStatus() !== ConnectionStatus::Closing && $connection->getStatus() !== ConnectionStatus::Closed) {
                    $disconnectPromises[] = $connection->disconnectAsync();
                }
            }
            unset($this->connectingPromises[$serverName]);
        }

        if (empty($disconnectPromises)) {
            $this->connections = [];

            return;
        }

        $allPromise = \React\Promise\all($disconnectPromises);

        try {
            PromiseAwaiter::await($allPromise, 10.0, $this->loop, 'DisconnectAll');
            $this->logger->info('Successfully disconnected all servers.');
        } catch (Throwable $e) {
            $this->logger->error("Error during disconnectAll: {$e->getMessage()}", ['exception' => $e]);
        } finally {
            $this->connections = [];
        }
    }

    /** Get the managed event loop */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }
}
