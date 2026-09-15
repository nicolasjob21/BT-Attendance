<?php

namespace App\Http\Controllers;

use App\Services\Checkpoint\CheckpointSettings;
use Illuminate\Http\Request;

/** Module defaults and the photo-instruction library. */
class CheckpointSettingsController extends Controller
{
    public function edit(CheckpointSettings $settings)
    {
        return view('checkpoints.settings', [
            'defaults' => $settings->defaults(),
            'instructions' => $settings->photoInstructions(),
        ]);
    }

    public function update(Request $request, CheckpointSettings $settings)
    {
        $data = $request->validate([
            'checkpoints_per_day' => ['required', 'integer', 'between:1,12'],
            'minimum_interval_minutes' => ['required', 'integer', 'between:5,720'],
            'maximum_interval_minutes' => ['required', 'integer', 'between:5,720', 'gte:minimum_interval_minutes'],
            'response_window_minutes' => ['required', 'integer', 'between:3,60'],
            'photo_instructions' => ['required', 'string', 'max:3000'],
        ]);

        $instructions = preg_split('/\r\n|\r|\n/', $data['photo_instructions']);
        unset($data['photo_instructions']);
        $settings->save($data, $instructions);

        return back()->with('status', 'Check Point settings saved.');
    }
}
