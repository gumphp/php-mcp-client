<?php

use PhpMcp\Client\Model\ClientInfo;

it('creates client info and converts to array', function () {
    // Arrange
    $name = 'TestClientApp';
    $version = '1.2.3';

    // Act
    $info = new ClientInfo($name, $version);
    $array = $info->toArray();

    // Assert
    expect($info->name)->toBe($name);
    expect($info->version)->toBe($version);
    expect($array)->toBe([
        'name' => $name,
        'version' => $version,
    ]);
});
