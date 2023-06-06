<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Detector;

class Ruby extends Detector
{
    const DETECTOR_RUBY = 'Ruby';

    const RUNTIME_RUBY = 'ruby';

    public function getLanguage(): string
    {
        return self::DETECTOR_RUBY;
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

    public function getRuntime(): string
    {
        return self::RUNTIME_RUBY;
    }

    public function detect(): ?bool
    {
        if (in_array('Gemfile', $this->files) || in_array('Gemfile.lock', $this->files)) {
            return true;
        }

        if (in_array(self::DETECTOR_RUBY, $this->languages)) {
            return true;
        }

        if (
            in_array('config.ru', $this->files) ||
            in_array('Rakefile', $this->files) ||
            in_array('Gemspec', $this->files) ||
            in_array('Capfile', $this->files)
        ) {
            return true;
        }

        if (in_array('Rakefile', $this->files) || in_array('Guardfile', $this->files)) {
            return true;
        }

        // foreach ($this->files as $file) {
        //     if (pathinfo($file, PATHINFO_EXTENSION) === 'rb') {
        //         return true;
        //     }
        // }

        return false;
    }
}
