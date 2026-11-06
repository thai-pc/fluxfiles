<?php
/**
 * The edition -> claim bundles that used to be hard-coded in FluxFilesPlugin::applyEditionPreset().
 * The free plugin now ships none (it reads apply_filters('fluxfiles_edition_presets', [])); this
 * is the reference map for the FluxFiles Pro plugin to register, and a fixture for the smoke.
 */
return [
    'pro'        => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true],
    'agency'     => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true],
    'studio'     => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true, 'allow_versioning' => true, 'allow_webhooks' => true, 'allow_ai_vision' => true, 'allow_ocr' => true],
    'enterprise' => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true, 'allow_versioning' => true, 'allow_webhooks' => true, 'allow_ai_vision' => true, 'allow_ocr' => true, 'allow_virus_scan' => true, 'allow_c2pa' => true, 'allow_backup' => true, 'allow_audit_export' => true, 'allow_dlp_scan' => true, 'allow_legal_hold' => true],
];
