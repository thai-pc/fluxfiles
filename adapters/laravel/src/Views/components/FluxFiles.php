<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Views\Components;

use FluxFiles\Laravel\FluxFilesManager;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class FluxFiles extends Component
{
    public string $disk;
    public string $mode;
    public bool $multiple;
    public string $width;
    public string $height;
    public array $overrides;
    public ?string $onSelect;
    public ?string $onClose;

    public function __construct(
        string $disk = 'local',
        string $mode = 'picker',
        bool $multiple = false,
        string $width = '100%',
        string $height = '600px',
        array $overrides = [],
        ?string $onSelect = null,
        ?string $onClose = null,
    ) {
        $this->disk = $disk;
        $this->mode = $mode;
        $this->multiple = $multiple;
        $this->width = $width;
        $this->height = $height;
        $this->overrides = $overrides;
        $this->onSelect = $onSelect;
        $this->onClose = $onClose;
    }

    public function render(): View
    {
        return view('fluxfiles::components.fluxfiles');
    }
}
