<?php

declare(strict_types=1);

namespace PhpMcp\Client;

use PhpMcp\Client\Contracts\TransportInterface;
use PhpMcp\Client\Enum\ConnectionStatus;
use PhpMcp\Client\Exception\ConnectionException;
use PhpMcp\Client\Exception\McpClientException;
use PhpMcp\Client\Exception\RequestException;
use PhpMcp\Client\Exception\TransportException;
use PhpMcp\Client\Factory\TransportFactory;
use PhpMcp\Client\JsonRpc\Message;
use PhpMcp\Client\JsonRpc\Notification;
use PhpMcp\Client\JsonRpc\Params\InitializeParams;
use PhpMcp\Client\JsonRpc\Request;
use PhpMcp\Client\JsonRpc\Response;
use PhpMcp\Client\JsonRpc\Results\InitializeResult;
use PhpMcp\Client\Model\Capabilities;
use PhpMcp\Client\Model\ServerInfo;
use PhpMcp\Client\Transport\Stdio\StdioClientTransport;
use Psr\Log\LoggerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Internal class representing an active connection to ONE MCP server.
 * Manages asynchronous communication, state, and request/response mapping.
 */
class ServerConnection
{
    private ConnectionStatus $status = ConnectionStatus::Disconnected;

    private TransportInterface $transport;

    private LoggerInterface $logger;

    private ?PromiseInterface $connectPromise = null;

    /** @var Deferred|null Connection request deferred */
    private ?Deferred $connectRequest = null;

    /** @var array<string|int, Deferred> Request ID => Deferred mapping */
    private array $pendingRequests = [];

    private ?ServerInfo $serverInfo = null;

    private ?Capabilities $serverCapabilities = null;

    private ?string $negotiatedProtocolVersion = null;

    private string $preferredProtocolVersion = '2024-11-05';

    public function __construct(
        public ServerConfig $serverConfig,
        private ClientConfig $clientConfig,
        private readonly TransportFactory $transportFactory
    ) {
        $this->logger = $this->clientConfig->logger;
    }

    public function getServerName(): string
    {
        return $this->serverConfig->name;
    }

    public function getStatus(): ConnectionStatus
    {
        return $this->status;
    }

    public function getServerInfo(): ?ServerInfo
    {
        return $this->serverInfo;
    }

    public function getNegotiatedCapabilities(): ?Capabilities
    {
        return $this->serverCapabilities;
    }

    public function getNegotiatedProtocolVersion(): ?string
    {
        return $this->negotiatedProtocolVersion;
    }

    /**
     * Initiates the asynchronous connection and handshake process.
     * Returns a promise that resolves with $this when ready, or rejects on error.
     */
    public function connectAsync(): PromiseInterface
    {
        if ($this->connectPromise !== null) {
            return $this->connectPromise;
        }

        if ($this->status !== ConnectionStatus::Disconnected && $this->status !== ConnectionStatus::Closed && $this->status !== ConnectionStatus::Error) {
            return \React\Promise\reject(new ConnectionException("Cannot connect, already in status: {$this->status->value}"));
        }

        $this->logger->info("Connecting to server '{$this->getServerName()}'...", ['transport' => $this->serverConfig->transport->value]);

        $this->connectRequest = new Deferred(function ($_, $reject) {
            $this->logger->info("Connection attempt for '{$this->getServerName()}' cancelled.");
            $this->handleConnectionFailure(new ConnectionException('Connection attempt cancelled.'), false);
            if (isset($this->transport) && ($this->status === ConnectionStatus::Connecting || $this->status === ConnectionStatus::Handshaking)) {
                $this->transport->close();
            }
        });

        $this->status = ConnectionStatus::Connecting;

        $this->transport = $this->transportFactory->create($this->serverConfig);

        $this->transport->on('message', $this->handleTransportMessage(...));
        $this->transport->on('error', $this->handleTransportError(...));
        $this->transport->on('close', $this->handleTransportClose(...));
        if ($this->transport instanceof StdioClientTransport) {
            $this->transport->on('stderr', function (string $data) {
                $this->logger->warning("Server '{$this->getServerName()}' STDERR: ".trim($data));
            });
        }

        // --- Define the connection and handshake sequence ---
        $this->transport->connect()
            ->then(
                function () {
                    if ($this->status === ConnectionStatus::Connecting) {
                        $this->logger->info("Transport connected for '{$this->getServerName()}', initiating handshake...");
                        $this->status = ConnectionStatus::Handshaking;
                    } else {
                        throw new ConnectionException("Connection status changed unexpectedly ({$this->status->value}) before handshake could start.");
                    }

                    return $this->performHandshake();
                }
            )
            ->then(
                function () {
                    if ($this->status === ConnectionStatus::Handshaking) {
                        $this->status = ConnectionStatus::Ready;
                        $this->logger->info("Server '{$this->getServerName()}' connection ready.", [
                            'protocol' => $this->negotiatedProtocolVersion,
                            'server' => $this->serverInfo?->name,
                            'version' => $this->serverInfo?->version,
                        ]);
                    } else {
                        throw new ConnectionException("Connection status changed unexpectedly ({$this->status->value}) during handshake.");
                    }

                    return $this;
                }
            )->catch(
                function (Throwable $error) {
                    $this->logger->error("Connection/Handshake failed for '{$this->getServerName()}': {$error->getMessage()}", ['exception' => $error]);
                    $this->handleConnectionFailure($error);
                }
            )->then(
                fn ($connection) => $this->connectRequest?->resolve($connection),
                fn (Throwable $error) => $this->connectRequest?->reject($error)
            )->finally(function () {
                $this->connectRequest = null;
            });

        $this->connectPromise = $this->connectRequest->promise();

        return $this->connectPromise;
    }

    /**
     * Performs the MCP initialize handshake. Returns a promise.
     */
    private function performHandshake(): PromiseInterface
    {
        $initParams = new InitializeParams(
            protocolVersion: $this->preferredProtocolVersion,
            capabilities: $this->clientConfig->capabilities,
            clientInfo: $this->clientConfig->clientInfo
        );

        $request = new Request(
            id: $this->clientConfig->idGenerator->generate(),
            method: 'initialize',
            params: $initParams->toArray()
        );

        return $this->sendAsync($request, false)->then(
            function (Response $response) {
                if ($response->isError()) {
                    throw new ConnectionException("Initialize failed: {$response->error->message}");
                }
                if (! is_array($response->result)) {
                    throw new ConnectionException('Invalid initialize result format.');
                }

                $initResult = InitializeResult::fromArray($response->result);

                $serverVersion = $initResult->protocolVersion;
                if ($serverVersion !== $this->preferredProtocolVersion) {
                    $this->logger->warning("Server '{$this->getServerName()}' uses different protocol version.", [
                        'client_preferred' => $this->preferredProtocolVersion,
                        'server_actual' => $serverVersion,
                    ]);
                    if (! is_string($serverVersion) || empty($serverVersion)) {
                        throw new ConnectionException('Server returned invalid protocol version in initialize response.');
                    }
                }
                $this->negotiatedProtocolVersion = $serverVersion;
                $this->serverInfo = $initResult->serverInfo;
                $this->serverCapabilities = $initResult->capabilities;

                $this->logger->debug("Sending 'initialized' notification to '{$this->getServerName()}'.");

                return $this->transport->send(new Notification('notifications/initialized'));
            }
        );
    }

    /**
     * Sends a standard MCP request asynchronously.
     * Returns a promise that resolves with the JsonRpc\Response.
     */
    public function sendAsync(Request $request, bool $checkStatus = true): PromiseInterface
    {
        if ($checkStatus && $this->status !== ConnectionStatus::Ready) {
            return \React\Promise\reject(new McpClientException("Cannot send request, connection not ready (Status: {$this->status->value})"));
        }

        if (! isset($request->id)) {
            return \React\Promise\reject(new McpClientException('Cannot use sendAsync for notifications.'));
        }

        $deferred = new Deferred(function ($_, $reject) use ($request) {
            if (isset($this->pendingRequests[$request->id])) {
                unset($this->pendingRequests[$request->id]);
                $reject(new McpClientException("Request '{$request->method}' (ID: {$request->id}) cancelled."));
            }
        });

        $this->pendingRequests[$request->id] = $deferred;

        $this->transport->send($request)->catch(function (Throwable $e) use ($deferred, $request) {
            if (isset($this->pendingRequests[$request->id])) {
                unset($this->pendingRequests[$request->id]);
                $deferred->reject(new TransportException("Failed to send request '{$request->method}': {$e->getMessage()}", 0, $e));
            }
        });

        return $deferred->promise();
    }

    /**
     * Closes the connection asynchronously.
     */
    public function disconnectAsync(): PromiseInterface
    {
        if ($this->status === ConnectionStatus::Closing || $this->status === ConnectionStatus::Closed) {
            return \React\Promise\resolve(null);
        }
        if ($this->status === ConnectionStatus::Disconnected || $this->status === ConnectionStatus::Error) {
            $this->status = ConnectionStatus::Closed;

            return \React\Promise\resolve(null);
        }

        $this->logger->info("Disconnecting from server '{$this->getServerName()}'...");
        $this->status = ConnectionStatus::Closing;

        $deferred = new Deferred;

        foreach ($this->pendingRequests as $id => $pendingDeferred) {
            $pendingDeferred->reject(new ConnectionException("Connection closing while request (ID: {$id}) was pending."));
        }
        $this->pendingRequests = [];

        $listener = function ($reason = null) use ($deferred) {
            $this->logger->info("Transport closed for server '{$this->getServerName()}'.", ['reason' => $reason]);

            if ($this->status !== ConnectionStatus::Closed) {
                $this->status = ConnectionStatus::Closed;

                $deferred->resolve(null);
            }
        };
        $this->transport->once('close', $listener);

        $closeTimeout = 5;
        $timer = $this->clientConfig->loop->addTimer($closeTimeout, function () use ($deferred, $listener, $closeTimeout) {
            if ($this->status !== ConnectionStatus::Closed) {
                $this->logger->warning("Transport did not confirm close within {$closeTimeout}s for '{$this->getServerName()}'. Forcing cleanup.");
                $this->transport->removeListener('close', $listener);
                $this->status = ConnectionStatus::Closed;
                $deferred->resolve(null);
            }
        });

        $deferred->promise()->finally(fn () => $this->clientConfig->loop->cancelTimer($timer));

        $this->transport->close();

        return $deferred->promise();
    }

    // --- Internal Event Handlers ---

    public function handleTransportMessage(Message $message): void
    {
        if ($message instanceof Response) {
            $this->handleResponseMessage($message);
        } elseif ($message instanceof Notification) {
            $this->handleNotificationMessage($message);
        } else {
            $this->logger->warning("Received unknown message type from '{$this->getServerName()}'");
        }
    }

    private function handleResponseMessage(Response $response): void
    {
        $id = $response->id;
        if ($id === null) {
            $this->logger->warning('Received Response message with null ID', ['response' => $response->toArray()]);

            return; // Ignore responses without ID?
        }

        if (! isset($this->pendingRequests[$id])) {
            $this->logger->warning('Received response for unknown or timed out request ID', ['id' => $id]);

            return;
        }

        $deferred = $this->pendingRequests[$id];
        unset($this->pendingRequests[$id]);

        if ($response->isError()) {
            $exception = new RequestException(
                $response->error->message,
                $response->error,
                $response->error->code
            );
            $deferred->reject($exception);
        } else {
            $deferred->resolve($response);
        }
    }

    private function handleNotificationMessage(Notification $notification): void
    {
        if (! $this->clientConfig->eventDispatcher) {
            return; // No dispatcher configured
        }

        // Map notification method to Event class
        $event = match ($notification->method) {
            'notifications/tools/listChanged' => new Event\ToolsListChanged($this->getServerName()),
            'notifications/resources/listChanged' => new Event\ResourcesListChanged($this->getServerName()),
            'notifications/prompts/listChanged' => new Event\PromptsListChanged($this->getServerName()),
            'notifications/resources/didChange' => new Event\ResourceChanged($this->getServerName(), $notification->params['uri'] ?? 'unknown'), // Assuming structure
            'notifications/logging/log' => new Event\LogReceived($this->getServerName(), $notification->params), // Assuming structure
            'sampling/createMessage' => new Event\SamplingRequestReceived($this->getServerName(), $notification->params), // Assuming structure
            default => null
        };

        if ($event) {
            $this->logger->debug('Dispatching event', ['event' => get_class($event), 'server' => $this->getServerName()]);
            try {
                $this->clientConfig->eventDispatcher->dispatch($event);
            } catch (Throwable $e) {
                $this->logger->error('Error dispatching MCP notification event', ['exception' => $e, 'event' => get_class($event)]);
            }
        } else {
            $this->logger->warning('Received unhandled notification method', ['method' => $notification->method, 'server' => $this->getServerName()]);
        }
    }

    private function handleTransportError(Throwable $error): void
    {
        // Check if already closing/closed/errored to avoid redundant actions
        if ($this->status === ConnectionStatus::Closing || $this->status === ConnectionStatus::Closed || $this->status === ConnectionStatus::Error) {
            $this->logger->debug("Ignoring transport error received in terminal state ({$this->status->value})", ['error' => $error->getMessage()]);

            return;
        }

        $this->logger->error("Transport error for '{$this->getServerName()}': {$error->getMessage()}", ['exception' => $error]);
        $this->handleConnectionFailure($error instanceof McpClientException ? $error : new ConnectionException('Transport layer error:  '.$error->getMessage(), 0, $error));
    }

    private function handleTransportClose(mixed $reason = null): void
    {
        if ($this->status === ConnectionStatus::Closing || $this->status === ConnectionStatus::Closed) {
            return;
        }

        $message = "Transport closed unexpectedly for '{$this->getServerName()}'.".($reason ? ' Reason: '.$reason : '');
        $this->logger->warning($message);
        $this->handleConnectionFailure(new ConnectionException($message));
    }

    /**
     * Centralized handler for fatal connection errors or unexpected closes.
     *
     * @param  bool  $rejectMasterDeferred  Should this call reject the master connectRequest? (Used by canceller)
     */
    public function handleConnectionFailure(Throwable $error, bool $rejectMasterDeferred = true): void
    {
        if ($this->status === ConnectionStatus::Closed || $this->status === ConnectionStatus::Error) {
            return;
        }

        $this->status = ConnectionStatus::Error;

        $exception = match (true) {
            $error instanceof ConnectionException => $error,
            $error instanceof RequestException => $error,
            default => new ConnectionException("Connection failed: {$error->getMessage()}", 0, $error),
        };

        if ($rejectMasterDeferred) {
            $this->connectRequest?->reject($exception);
        }

        foreach ($this->pendingRequests as $deferred) {
            $deferred->reject($exception);
        }
        $this->pendingRequests = [];

        $this->connectPromise = null;
        $this->transport->close();
        $this->logger->info("Connection failure handled for '{$this->getServerName()}'. Status set to {$this->status->value}.");
    }
}
