<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Mark one notification read, then jump to whatever it points at. */
    public function open(Request $request, string $id)
    {
        $note = $request->user()->notifications()->findOrFail($id);
        $note->markAsRead();

        return redirect($note->data['url'] ?? route('dashboard'));
    }

    /** Mark every notification read. */
    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'All notifications marked as read.');
    }
}
