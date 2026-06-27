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
    public ?string $onTokenRefresh;
    public string $tokenUrl;

    public function __construct(
        string $disk = 'local',
        string $mode = 'picker',
        ?bool $multiple = null,
        string $width = '100%',
        string $height = '600px',
        array $overrides = [],
        ?string $onSelect = null,
        ?string $onClose = null,
        ?string $onTokenRefresh = null,
    ) {
        $this->disk = $disk;
        $this->mode = $mode;
        // Fall back to the config UI default when not set on the tag.
        $this->multiple = $multiple ?? (bool) config('fluxfiles.multiple', false);
        $this->width = $width;
        $this->height = $height;
        $this->overrides = $overrides;
        $this->onSelect = $onSelect;
        $this->onClose = $onClose;
        $this->onTokenRefresh = $onTokenRefresh;
        // Same-origin URL the default onTokenRefresh hook hits to re-mint a JWT
        // from the Laravel session after the embedded one expires.
        $this->tokenUrl = url(config('fluxfiles.route_prefix', 'api/fm') . '/token');
    }

    public function render(): View
    {
        return view('fluxfiles::components.fluxfiles');
    }
}
