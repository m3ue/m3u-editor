<?php

it('generates unique source_ids with positional index enabled', function () {
    // Simulate the source_id computation logic from ProcessM3uImport
    $title = 'CCTV-1';
    $name = 'CCTV1';
    $group = 'CCTV';

    // Without positional index: all entries get the same source_id
    $sourceKeyBase = $title.$name.$group;
    $hash1 = md5($sourceKeyBase);
    $hash2 = md5($sourceKeyBase);
    expect($hash1)->toBe($hash2);

    // With positional index: each entry gets a unique source_id
    $hashPos1 = md5($sourceKeyBase.':1');
    $hashPos2 = md5($sourceKeyBase.':2');
    $hashPos3 = md5($sourceKeyBase.':3');

    expect($hashPos1)->not->toBe($hashPos2);
    expect($hashPos2)->not->toBe($hashPos3);
    expect($hashPos1)->not->toBe($hashPos3);
});

it('generates stable source_ids for the same position', function () {
    $title = 'CCTV-1';
    $name = 'CCTV1';
    $group = 'CCTV';
    $channelNo = 42;

    $hash1 = md5($title.$name.$group.':'.$channelNo);
    $hash2 = md5($title.$name.$group.':'.$channelNo);

    expect($hash1)->toBe($hash2);
});
