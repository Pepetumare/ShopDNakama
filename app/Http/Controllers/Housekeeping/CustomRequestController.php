<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\CustomRequestFilter;
use App\Http\Requests\Housekeeping\UpdateCustomRequest;
use App\Models\CustomRequest;
use App\Services\ManageCustomQuote;
use Illuminate\Support\Facades\Gate;

class CustomRequestController extends Controller
{
    public function index(CustomRequestFilter $request)
    {
        $filters = $request->validated();
        $query = CustomRequest::with(['files', 'order']);
        foreach (['status', 'product_type'] as $field) {
            if (! empty($filters[$field])) {
                if ($field === 'product_type' && $filters[$field] === 'patch') {
                    $query->whereIn($field, ['patch', ...CustomRequest::LEGACY_PATCH_TYPES]);
                } else {
                    $query->where($field, $filters[$field]);
                }
            }
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['q'])) {
            $query->where(function ($query) use ($filters) {
                foreach (['request_number', 'customer_name', 'customer_email'] as $field) {
                    $query->orWhere($field, 'like', '%'.$filters['q'].'%');
                }
            });
        }

        return view('housekeeping.custom-requests.index', ['customRequests' => $query->latest('id')->paginate(15)->withQueryString()]);
    }

    public function show(CustomRequest $customRequest)
    {
        Gate::authorize('manage', $customRequest);

        return view('housekeeping.custom-requests.show', ['customRequest' => $customRequest->load(['files', 'order'])]);
    }

    public function update(UpdateCustomRequest $request, CustomRequest $customRequest, ManageCustomQuote $quotes)
    {
        Gate::authorize('manage', $customRequest);
        $quotes->update($customRequest, $request->validated());

        return to_route('housekeeping.custom-requests.show', $customRequest)->with('status', 'Solicitud actualizada.');
    }
}
