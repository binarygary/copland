<?php

use App\Commands\SetupCommand;

it('setup command prints the deprecation notice', function () {
    $this->artisan('setup')
        ->expectsOutput('⚠ `copland setup` is deprecated — use `copland automate` instead.')
        ->expectsOutput('Running `copland automate`...');
});

it('setup command is hidden from help', function () {
    $command = new SetupCommand;
    $command->setLaravel($this->app);

    expect($command->isHidden())->toBeTrue();
});
