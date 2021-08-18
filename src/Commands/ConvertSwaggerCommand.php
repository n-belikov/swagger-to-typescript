<?php

namespace NiBurkin\STT\Commands;

use Illuminate\Console\Command;
use Atwinta\STT\Convert;

/**
 * Class ConvertSwaggerCommand
 * @package NiBurkin\STT\Commands
 */
class ConvertSwaggerCommand extends Command
{
    protected $signature = "convert:swagger {path} {output}";

    public function handle(Convert $convert)
    {
        $path = base_path($this->argument("path"));
        $output = base_path($this->argument("output"));

        $result = $convert->convert($path);

        file_put_contents(
            $output,
            $result
        );

        return 1;
    }
}