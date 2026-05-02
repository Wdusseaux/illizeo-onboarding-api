<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    /**
     * Download a PDF invoice. Generates the PDF on the fly if not yet stored.
     * Authorisation: tenant scope (current tenant), or super-admin (any tenant).
     */
    public function download(Request $request, int $id)
    {
        $user = $request->user();
        $isSuperAdmin = $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $query = Invoice::where('id', $id);
        if (!$isSuperAdmin) {
            $tenantId = tenant('id');
            if (!$tenantId) {
                return response()->json(['error' => 'Tenant scope required'], Response::HTTP_FORBIDDEN);
            }
            $query->where('tenant_id', $tenantId);
        }

        $invoice = $query->first();
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $invoice->pdf_path
            ? storage_path('app/' . $invoice->pdf_path)
            : null;

        if (!$absolutePath || !file_exists($absolutePath)) {
            $service = new InvoicePdfService();
            $absolutePath = $service->generate($invoice);
        }

        if (!file_exists($absolutePath)) {
            return response()->json(['error' => 'PDF generation failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->download(
            $absolutePath,
            $invoice->invoice_number . '.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * Force-regenerate the PDF (super-admin only).
     */
    public function regenerate(Request $request, int $id)
    {
        $user = $request->user();
        $isSuperAdmin = $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        if (!$isSuperAdmin) {
            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $invoice = Invoice::find($id);
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], Response::HTTP_NOT_FOUND);
        }

        $service = new InvoicePdfService();
        $path = $service->generate($invoice);

        return response()->json([
            'ok' => true,
            'invoice_number' => $invoice->invoice_number,
            'pdf_path' => $invoice->pdf_path,
            'absolute_path' => $path,
        ]);
    }
}
