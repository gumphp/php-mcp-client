<?php

use PhpMcp\Client\Model\ServerInfo;

it('creates server info from array', function () {
    // Arrange
    $data = [
        'name' => 'TestServer',
        'version' => '0.9.0',
        'extraFieldIgnored' => 'should not be included',
    ];

    // Act
    $info = ServerInfo::fromArray($data);

    // Assert
    expect($info->name)->toBe('TestServer');
    expect($info->version)->toBe('0.9.0');
});

it('uses defaults for missing fields in server info from array', function () {
    // Arrange
    $dataMissingVersion = ['name' => 'TestServerOnly'];
    $dataMissingName = ['version' => '1.1'];
    $dataEmpty = [];

    // Act
    $info1 = ServerInfo::fromArray($dataMissingVersion);
    $info2 = ServerInfo::fromArray($dataMissingName);
    $info3 = ServerInfo::fromArray($dataEmpty);

    // Assert
    expect($info1->name)->toBe('TestServerOnly');
    expect($info1->version)->toBe('Unknown Version');

    expect($info2->name)->toBe('Unknown Server');
    expect($info2->version)->toBe('1.1');

    expect($info3->name)->toBe('Unknown Server');
    expect($info3->version)->toBe('Unknown Version');
});
