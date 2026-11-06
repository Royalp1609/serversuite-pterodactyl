<?php

// A repeated key in a PHP array silently replaces the first one: a second
// 'versions' block once removed paper_url, so the Versions tab listed nothing.
it('keeps the Paper source and the MCJars switch in the versions config', function () {
    $versions = (require __DIR__ . '/../../config/serversuite.php')['versions'];

    expect($versions['paper_url'])->toStartWith('https://')
        ->and($versions['user_agent'])->not->toBeEmpty()
        ->and($versions['mcjars'])->toBeTrue();
});
