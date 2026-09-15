<?php

namespace App\Http\Controllers;

use App\Services\Checkpoint\CheckpointSettings;
use Illuminate\Http\Request;

/** Module defaults and the instruction library. */
class CheckpointSettingsController extends Controller
{
    public function edit(CheckpointSettings $settings)
    {
        return view('checkpoints.settings', [
            'defaults' => $settings->defaults(),
            'instructions' => $settings->instructions(),
        ]);
    }

    public function update(Request $request, CheckpointSettings $settings)
    {
        $data = $request->validate([
            'response_window_minutes' => ['required', 'integer', 'between:3,120'],
            'instructions' => ['required', 'string', 'max:3000'],
        ]);

        $instructions = preg_split('/\r\n|\r|\n/', $data['instructions']);
        unset($data['instructions']);
        $settings->save($data, $instructions);

        return back()->with('status', 'Check Point settings saved.');
    }
}
