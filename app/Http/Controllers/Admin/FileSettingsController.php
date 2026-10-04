<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Files\AttachmentService;
use App\Domain\Settings\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin: largest allowed upload and which file types members may attach.
 */
class FileSettingsController extends Controller
{
    public function edit(AttachmentService $files): Response
    {
        return Inertia::render('admin/FileSettings', [
            'maxMb' => $files->maxMb(),
            'groups' => collect((array) config('files.groups'))
                ->map(fn (array $g, string $key) => ['key' => $key, 'label' => $g['label'], 'ext' => $g['ext']])
                ->values(),
            'enabled' => $files->allowedGroups(),
            'driver' => config('files.driver'),
        ]);
    }

    public function update(Request $request, AppSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'max_mb' => ['required', 'integer', 'min:1', 'max:200'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => [Rule::in(array_keys((array) config('files.groups')))],
        ]);

        $settings->set('files.max_mb', (int) $data['max_mb']);
        $settings->set('files.groups', array_values(array_unique($data['groups'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File settings saved.')]);

        return back();
    }
}
