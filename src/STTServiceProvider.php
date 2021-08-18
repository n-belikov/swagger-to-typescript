<?php

namespace NiBurkin\STT;

use Illuminate\Support\ServiceProvider;
use NiBurkin\STT\Commands\ConvertSwaggerCommand;

/**
 * Class STTServiceProvider
 * @package NiBurkin\STT
 */
class STTServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            ConvertSwaggerCommand::class
        ]);
    }
}