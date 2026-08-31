<?php

use FluxFiles\Laravel\Http\Controllers\FluxFilesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FluxFiles API Routes (proxy mode)
|--------------------------------------------------------------------------
|
| These routes proxy all FluxFiles API endpoints through Laravel,
| so there's no need to deploy FluxFiles as a separate server.
|
*/

// Language routes (public, no auth needed)
Route::get('lang', [FluxFilesController::class, 'langList'])->withoutMiddleware(['auth']);
Route::get('lang/{locale}', [FluxFilesController::class, 'langGet'])->withoutMiddleware(['auth']);

// API routes
Route::get('list', [FluxFilesController::class, 'list']);
Route::post('upload', [FluxFilesController::class, 'upload']);
Route::post('import-url', [FluxFilesController::class, 'importUrl']);
Route::delete('delete', [FluxFilesController::class, 'delete']);
Route::post('rename', [FluxFilesController::class, 'rename']);
Route::post('move', [FluxFilesController::class, 'move']);
Route::post('copy', [FluxFilesController::class, 'copy']);
Route::post('mkdir', [FluxFilesController::class, 'mkdir']);
Route::post('cross-copy', [FluxFilesController::class, 'crossCopy']);
Route::post('cross-move', [FluxFilesController::class, 'crossMove']);
Route::post('crop', [FluxFilesController::class, 'crop']);
Route::post('watermark', [FluxFilesController::class, 'watermark']);
Route::post('watermark/remove', [FluxFilesController::class, 'watermarkRemove']);
Route::post('ai-tag', [FluxFilesController::class, 'aiTag']);
Route::post('presign', [FluxFilesController::class, 'presign']);
Route::get('meta', [FluxFilesController::class, 'meta']);

// Metadata
Route::get('metadata', [FluxFilesController::class, 'getMetadata']);
Route::put('metadata', [FluxFilesController::class, 'saveMetadata']);
Route::delete('metadata', [FluxFilesController::class, 'deleteMetadata']);

// Search
Route::get('search', [FluxFilesController::class, 'search']);
Route::get('search-folders', [FluxFilesController::class, 'searchFolders']);

// Trash (soft-delete) — gated by the 'delete' permission inside FileManager
Route::post('trash', [FluxFilesController::class, 'trash']);
Route::post('trash/restore', [FluxFilesController::class, 'trashRestore']);
Route::get('trash/list', [FluxFilesController::class, 'trashList']);
Route::post('trash/purge', [FluxFilesController::class, 'trashPurge']);
Route::post('trash/empty', [FluxFilesController::class, 'trashEmpty']);

// Bucket Doctor — diagnose a disk backend (creds/permissions/CORS/presign)
Route::get('disk/doctor', [FluxFilesController::class, 'diskDoctor']);

// Quota
Route::get('quota', [FluxFilesController::class, 'quota']);
Route::get('usage', [FluxFilesController::class, 'usage']);

// Commercial edition / license status (server-wide; free core → edition:free)
Route::get('license', [FluxFilesController::class, 'license']);

// Optimization (paid module; gated by ModuleRegistry → 501/402/403 when not entitled)
Route::post('optimize', [FluxFilesController::class, 'optimize']);

// Config / code editor (works on any disk)
Route::get('content', [FluxFilesController::class, 'getContent']);
Route::put('content', [FluxFilesController::class, 'putContent']);

// Extract a zip in place (works on any disk; returns JSON)
Route::post('extract', [FluxFilesController::class, 'extract']);

// Audit
Route::get('audit', [FluxFilesController::class, 'audit']);

// Audit export/purge (paid module)
Route::get('audit/export', [FluxFilesController::class, 'auditExport']);
Route::post('audit/purge', [FluxFilesController::class, 'auditPurge']);

// Webhooks (paid module) — send a test ping to the configured endpoint
Route::post('webhooks/test', [FluxFilesController::class, 'webhooksTest']);

// File versioning (paid module) — list prior versions of a file / restore one
Route::get('versions', [FluxFilesController::class, 'versions']);
Route::post('versions/restore', [FluxFilesController::class, 'versionsRestore']);

// AI Vision / OCR / Backup Bridge / C2PA (paid modules)
Route::post('ai-vision', [FluxFilesController::class, 'aiVision']);
Route::post('ocr', [FluxFilesController::class, 'ocr']);
Route::post('backup', [FluxFilesController::class, 'backup']);
Route::post('c2pa', [FluxFilesController::class, 'c2pa']);
Route::post('c2pa/sign', [FluxFilesController::class, 'c2paSign']);

// Share + Intake (paid module) — operator side: create/list/revoke/analytics.
// The public recipient routes (share/info, share/unlock, share/file, intake/info,
// intake/upload) and the recipient landing pages are registered separately in
// FluxFilesServiceProvider, outside the auth middleware group.
Route::post('share', [FluxFilesController::class, 'share']);
Route::get('share/list', [FluxFilesController::class, 'shareList']);
Route::post('share/revoke', [FluxFilesController::class, 'shareRevoke']);
Route::get('share/analytics', [FluxFilesController::class, 'shareAnalytics']);
Route::post('intake', [FluxFilesController::class, 'intake']);
Route::get('intake/list', [FluxFilesController::class, 'intakeList']);
Route::post('intake/revoke', [FluxFilesController::class, 'intakeRevoke']);
Route::get('intake/analytics', [FluxFilesController::class, 'intakeAnalytics']);

// Chunk upload (multipart)
Route::post('chunk/init', [FluxFilesController::class, 'chunkInit']);
Route::post('chunk/presign', [FluxFilesController::class, 'chunkPresign']);
Route::post('chunk/complete', [FluxFilesController::class, 'chunkComplete']);
Route::post('chunk/abort', [FluxFilesController::class, 'chunkAbort']);
