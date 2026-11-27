<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Models\TransactionalNotification;
use App\Services\TransactionalNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmailController extends Controller
{
    public function index(): View
    {
        return view('housekeeping.emails.index', [
            'notifications' => TransactionalNotification::with(['order', 'events'])->latest()->paginate(30),
        ]);
    }

    public function retry(TransactionalNotification $notification, TransactionalNotifications $service): RedirectResponse
    {
        abort_unless($notification->status === 'failed', 409);
        $service->retry($notification);

        return to_route('housekeeping.emails.index')->with('status', 'Reintento de correo encolado.');
    }
}
