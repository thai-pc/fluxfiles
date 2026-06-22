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

// Chunk upload (multipart)
Route::post('chunk/init', [FluxFilesController::class, 'chunkInit']);
Route::post('chunk/presign', [FluxFilesController::class, 'chunkPresign']);
Route::post('chunk/complete', [FluxFilesController::class, 'chunkComplete']);
Route::post('chunk/abort', [FluxFilesController::class, 'chunkAbort']);
