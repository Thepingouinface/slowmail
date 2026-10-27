<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignExportController;
use App\Http\Controllers\CampaignJournalController;
use App\Http\Controllers\CampaignLifecycleController;
use App\Http\Controllers\CampaignSettingsController;
use App\Http\Controllers\CampaignTemplateController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\RecipientController;
use App\Http\Controllers\SuppressionController;
use App\Http\Middleware\RequireSlowmailPassword;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');

Route::middleware(RequireSlowmailPassword::class)->group(function () {
    Route::redirect('/', '/campaigns');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');

    Route::get('/campaigns/{campaign}/template', [CampaignTemplateController::class, 'edit'])->name('campaigns.template');
    Route::put('/campaigns/{campaign}/template', [CampaignTemplateController::class, 'update'])->name('campaigns.template.update');
    Route::post('/campaigns/{campaign}/template/test', [CampaignTemplateController::class, 'test'])->name('campaigns.template.test');

    Route::post('/campaigns/{campaign}/import', [ImportController::class, 'analyze'])->name('campaigns.import.analyze');
    Route::get('/campaigns/{campaign}/settings', [CampaignSettingsController::class, 'edit'])->name('campaigns.settings');
    Route::put('/campaigns/{campaign}/settings', [CampaignSettingsController::class, 'update'])->name('campaigns.settings.update');
    Route::post('/campaigns/{campaign}/settings/release-simulated', [CampaignSettingsController::class, 'releaseSimulated'])->name('campaigns.settings.release-simulated');

    Route::get('/campaigns/{campaign}/journal', [CampaignJournalController::class, 'index'])->name('campaigns.journal');
    Route::get('/campaigns/{campaign}/export', CampaignExportController::class)->name('campaigns.export');

    Route::post('/campaigns/{campaign}/activate', [CampaignLifecycleController::class, 'activate'])->name('campaigns.activate');
    Route::post('/campaigns/{campaign}/pause', [CampaignLifecycleController::class, 'pause'])->name('campaigns.pause');
    Route::post('/campaigns/{campaign}/resume', [CampaignLifecycleController::class, 'resume'])->name('campaigns.resume');

    // La ligne doit appartenir à la campagne de l'URL : sans cela, un identifiant d'une autre campagne passerait.
    Route::scopeBindings()->group(function () {
        Route::post('/campaigns/{campaign}/recipients/{recipient}/reply', [RecipientController::class, 'reply'])->name('recipients.reply');
        Route::post('/campaigns/{campaign}/recipients/{recipient}/confirm-sent', [RecipientController::class, 'confirmSent'])->name('recipients.confirm-sent');
        Route::post('/campaigns/{campaign}/recipients/{recipient}/not-sent', [RecipientController::class, 'notSent'])->name('recipients.not-sent');
    });

    Route::get('/suppressions', [SuppressionController::class, 'index'])->name('suppressions.index');
    Route::post('/suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');

    Route::post('/campaigns/{campaign}/import/{token}', [ImportController::class, 'confirm'])->name('campaigns.import.confirm');
    Route::delete('/campaigns/{campaign}/import/{token}', [ImportController::class, 'cancel'])->name('campaigns.import.cancel');
});
