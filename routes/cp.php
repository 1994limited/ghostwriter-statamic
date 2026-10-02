<?php

use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\CollectionController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\DashboardController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\EntryController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\ImageryController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\ImagesController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\PlanController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\SessionController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\SetupController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\TypeController;
use NineteenNinetyFour\Ghostwriter\Http\Controllers\VoiceController;
use NineteenNinetyFour\Ghostwriter\Http\Middleware\AuthorizeGhostwriter;

Route::prefix('ghostwriter')->name('ghostwriter.')->middleware(AuthorizeGhostwriter::class)->group(function () {
    Route::get('/', DashboardController::class)->name('index');

    Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
    Route::get('setup/status', [SetupController::class, 'status'])->name('setup.status');
    Route::post('setup/hide', [SetupController::class, 'hide'])->name('setup.hide');
    Route::post('setup/collections', [SetupController::class, 'collections'])->name('setup.collections');

    Route::get('voice', [VoiceController::class, 'show'])->name('voice.show');
    Route::get('voice/status', [VoiceController::class, 'status'])->name('voice.status');
    Route::post('voice/scan', [VoiceController::class, 'scan'])->name('voice.scan');
    Route::patch('voice', [VoiceController::class, 'update'])->name('voice.update');
    Route::post('voice/refine', [VoiceController::class, 'refine'])->name('voice.refine');

    Route::get('images/tools', [ImagesController::class, 'tools'])->name('images.tools');
    Route::post('images', [ImagesController::class, 'start'])->name('images.start');
    Route::get('images/{id}', [ImagesController::class, 'status'])->name('images.status');
    Route::get('images/{id}/preview', [ImagesController::class, 'preview'])->name('images.preview');
    Route::post('images/{id}/use', [ImagesController::class, 'use'])->name('images.use');

    Route::get('plan', [PlanController::class, 'show'])->name('plan.show');
    Route::get('plan/status', [PlanController::class, 'status'])->name('plan.status');
    Route::post('plan/suggest', [PlanController::class, 'suggest'])->name('plan.suggest');
    Route::post('plan/ideas', [PlanController::class, 'store'])->name('plan.store');
    Route::post('plan/accept', [PlanController::class, 'accept'])->name('plan.accept');
    Route::delete('plan/ideas', [PlanController::class, 'clear'])->name('plan.clear');
    Route::patch('plan/ideas/{idea}', [PlanController::class, 'update'])->name('plan.update');
    Route::delete('plan/ideas/{idea}', [PlanController::class, 'destroy'])->name('plan.destroy');

    Route::get('imagery', [ImageryController::class, 'show'])->name('imagery.show');
    Route::get('imagery/status', [ImageryController::class, 'status'])->name('imagery.status');
    Route::post('imagery/scan', [ImageryController::class, 'scan'])->name('imagery.scan');
    Route::patch('imagery', [ImageryController::class, 'update'])->name('imagery.update');

    // Used by the panel that opens on an entry's publish form.
    Route::post('kinds/suggest', [CollectionController::class, 'suggestKindsEverywhere'])->name('kinds.suggest_all');
    Route::get('collections/{collection}', [CollectionController::class, 'show'])->name('collections.show');
    Route::get('collections/{collection}/kinds', [CollectionController::class, 'kinds'])->name('kinds.show');
    Route::post('collections/{collection}/kinds/suggest', [CollectionController::class, 'suggestKinds'])->name('kinds.suggest');
    Route::post('collections/{collection}/kinds/learn-all', [CollectionController::class, 'learnAllKinds'])->name('kinds.learn_all');
    Route::post('collections/{collection}/kinds/{id}/learn', [CollectionController::class, 'learnKind'])->name('kinds.learn');
    Route::post('collections/{collection}/kinds/{id}/dismiss', [CollectionController::class, 'dismissKind'])->name('kinds.dismiss');
    Route::post('collections/{collection}/analyse', [CollectionController::class, 'analyse'])->name('collections.analyse');

    Route::get('types/{type}', [TypeController::class, 'edit'])->name('types.edit');
    Route::patch('types/{type}', [TypeController::class, 'update'])->name('types.update');
    Route::delete('types/{type}', [TypeController::class, 'destroy'])->name('types.destroy');

    Route::post('entries/{entry}/session', [EntryController::class, 'session'])->name('entries.session');
    Route::post('types/{type}/brief', [SessionController::class, 'brief'])->name('types.brief');
    Route::post('types/{type}/sessions', [SessionController::class, 'store'])->name('sessions.store');
    Route::get('sessions/{session}', [SessionController::class, 'show'])->name('sessions.show');
    Route::get('sessions/{session}/open', [SessionController::class, 'open'])->name('sessions.open');
    Route::post('sessions/{session}/messages', [SessionController::class, 'message'])->name('sessions.message');
    Route::post('sessions/{session}/retry', [SessionController::class, 'retry'])->name('sessions.retry');
    Route::patch('sessions/{session}/field', [SessionController::class, 'editField'])->name('sessions.field');
    Route::patch('sessions/{session}/draft', [SessionController::class, 'draft'])->name('sessions.draft');
    Route::post('sessions/{session}/apply', [SessionController::class, 'apply'])->name('sessions.apply');
    Route::post('sessions/{session}/images', [SessionController::class, 'image'])->name('sessions.image');
    Route::get('sessions/{session}/photos', [SessionController::class, 'photos'])->name('sessions.photos');
    Route::post('sessions/{session}/photos', [SessionController::class, 'photo'])->name('sessions.photo');
    Route::post('sessions/{session}/images/copy', [SessionController::class, 'copyImage'])->name('sessions.image.copy');
    Route::post('sessions/{session}/entry', [SessionController::class, 'entry'])->name('sessions.entry');
    Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');
});
