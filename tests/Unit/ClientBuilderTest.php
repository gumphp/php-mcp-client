<?php

use PhpMcp\Client\Client;
use PhpMcp\Client\ClientConfig;
use PhpMcp\Client\Enum\TransportType;
use PhpMcp\Client\Exception\ConfigurationException;
use PhpMcp\Client\Factory\MessageIdGenerator;
use PhpMcp\Client\Model\Capabilities;
use PhpMcp\Client\Model\ClientInfo;
use PhpMcp\Client\ServerConfig;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\LoopInterface;

it('builds client with minimal configuration', function () {
    // Arrange
    $info = new ClientInfo('MinClient', '0.1');

    // Act
    $client = Client::make()
        ->withClientInfo($info)
        ->withServerConfig(new ServerConfig(name: 's1', transport: TransportType::Stdio, command: 'c'))
        ->build();

    // Assert
    expect($client)->toBeInstanceOf(Client::class);

    $reflector = new ReflectionClass($client);
    $configProp = $reflector->getProperty('clientConfig');
    $configProp->setAccessible(true);
    $internalConfig = $configProp->getValue($client);

    expect($internalConfig)->toBeInstanceOf(ClientConfig::class);
    expect($internalConfig->clientInfo)->toBe($info);
    expect($internalConfig->capabilities)->toBeInstanceOf(Capabilities::class);
    expect($internalConfig->logger)->toBeInstanceOf(\Psr\Log\NullLogger::class);
    expect($internalConfig->cache)->toBeNull();
    expect($internalConfig->eventDispatcher)->toBeNull();
    expect($internalConfig->loop)->toBeInstanceOf(LoopInterface::class);
});

it('builds client with all configurations', function () {
    // Arrange
    $info = new ClientInfo('FullClient', '1.1');
    $caps = Capabilities::forClient(supportsSampling: false, supportsRootListChanged: true);
    $logger = Mockery::mock(LoggerInterface::class);
    $cache = Mockery::mock(CacheInterface::class);
    $dispatcher = Mockery::mock(EventDispatcherInterface::class);
    $loop = Mockery::mock(LoopInterface::class);
    $idGen = new MessageIdGenerator('test-');
    $server1 = new ServerConfig(name: 's1', transport: TransportType::Stdio, command: 'c');
    $server2 = new ServerConfig(name: 's2', transport: TransportType::Http, url: 'http://s');

    // Act
    $client = Client::make()
        ->withClientInfo($info)
        ->withCapabilities($caps)
        ->withLogger($logger)
        ->withCache($cache, 900)
        ->withEventDispatcher($dispatcher)
        ->withLoop($loop)
        ->withIdGenerator($idGen)
        ->withServerConfig($server1)
        ->build();

    // Assert
    expect($client)->toBeInstanceOf(Client::class);
    $reflector = new ReflectionClass($client);
    $configProp = $reflector->getProperty('clientConfig');
    $configProp->setAccessible(true);
    $internalConfig = $configProp->getValue($client);

    expect($internalConfig->clientInfo)->toBe($info);
    expect($internalConfig->capabilities)->toBe($caps);
    expect($internalConfig->logger)->toBe($logger);
    expect($internalConfig->cache)->toBe($cache);
    expect($internalConfig->definitionCacheTtl)->toBe(900);
    expect($internalConfig->eventDispatcher)->toBe($dispatcher);
    expect($internalConfig->loop)->toBe($loop);
});

it('throws exception if client info not provided', function () {
    Client::make()->build();
})->throws(ConfigurationException::class, 'ClientInfo must be provided');
