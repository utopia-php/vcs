<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Ruby extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['Ruby'];
    }

    public function getRuntime(): string
    {
        return 'ruby';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['rb'];
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return ['Gemfile', 'Gemfile.lock', 'Rakefile', 'Guardfile'];
    }

    public function getInstallCommand(): string
    {
        return 'bundle install';
    }

    public function getBuildCommand(): string
    {
        return '';
    }

    public function getEntryPoint(): string
    {
        return 'main.rb';
    }
}
