<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ViesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantRegistrationController extends Controller
{
    /**
     * Register a new tenant (company) and create the first admin user.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'plan_ids' => 'sometimes|array',
            'plan_ids.*' => 'integer|exists:plans,id',
            // Billing fields (Feature 2 — required for VAT-compliant invoicing)
            'country' => 'required|string|size:2',
            'customer_type' => 'required|in:company,individual',
            'vat_number' => 'nullable|string|max:32',
            'billing_address' => 'nullable|array',
            'billing_address.street' => 'nullable|string|max:255',
            'billing_address.postal_code' => 'nullable|string|max:20',
            'billing_address.city' => 'nullable|string|max:100',
        ]);

        // VIES validation for EU B2B with VAT number
        $vatStatus = 'not_required';
        $vatValidatedAt = null;
        if (!empty($request->vat_number) && $request->customer_type === 'company') {
            $country = strtoupper($request->country);
            $isEu = in_array($country, [
                'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE',
                'IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
            ], true);
            if ($isEu) {
                try {
                    $result = ViesService::validate($country, $request->vat_number);
                    $vatStatus = $result['valid'] ? 'valid' : 'invalid';
                    $vatValidatedAt = now()->toIso8601String();
                } catch (\Throwable $e) {
                    \Log::warning('VIES check failed at signup: ' . $e->getMessage());
                    $vatStatus = 'pending';
                }
            } elseif ($country === 'CH') {
                // CH VAT format: CHE-XXX.XXX.XXX (not validated by VIES)
                $vatStatus = preg_match('/^CHE-?\d{3}\.?\d{3}\.?\d{3}/', $request->vat_number) ? 'valid' : 'pending';
                $vatValidatedAt = now()->toIso8601String();
            } else {
                $vatStatus = 'pending';
            }
        }

        // Generate a unique tenant ID from company name
        $tenantId = Str::slug($request->company_name);

        // Ensure uniqueness
        $baseTenantId = $tenantId;
        $counter = 1;
        while (Tenant::find($tenantId)) {
            $tenantId = $baseTenantId . '-' . $counter;
            $counter++;
        }

        // Check if tenant slug already exists
        if (Tenant::find($tenantId)) {
            return response()->json([
                'message' => "Le nom d'entreprise « {$request->company_name} » est déjà pris. Veuillez choisir un autre nom.",
                'errors' => ['company_name' => ["Ce nom d'entreprise est déjà utilisé."]],
            ], 422);
        }

        try {
            // Everything runs in a subprocess to avoid stancl tenancy config issues in HTTP context
            $phpBin = PHP_BINARY;
            if (empty($phpBin) || !is_executable($phpBin)) {
                $phpBin = trim(shell_exec('which php') ?? '') ?: '/usr/bin/php';
            }
            $scriptPath = base_path("_reg_{$tenantId}.php");
            $companyName = addslashes($request->company_name);
            $adminName = addslashes($request->admin_name);
            $adminEmail = addslashes($request->admin_email);
            $passwordHash = Hash::make($request->password);
            $passwordHashEscaped = addslashes($passwordHash);

            $country = strtoupper($request->country);
            $customerType = $request->customer_type;
            $vatNumber = addslashes($request->vat_number ?? '');
            $billingAddressJson = addslashes(json_encode($request->billing_address ?? []));

            file_put_contents($scriptPath, <<<SCRIPT
<?php
require __DIR__ . '/vendor/autoload.php';
\$app = require_once __DIR__ . '/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    // Create tenant (triggers DB creation + migrations + seeder via TenancyServiceProvider)
    \$tenant = App\Models\Tenant::create([
        'id' => '{$tenantId}',
        'nom' => '{$companyName}',
        'slug' => '{$tenantId}',
        'plan' => 'starter',
        'actif' => true,
        'billing_email' => '{$adminEmail}',
        'country' => '{$country}',
        'customer_type' => '{$customerType}',
        'vat_number' => '{$vatNumber}' ?: null,
        'vat_validation_status' => '{$vatStatus}',
        'vat_validated_at' => '{$vatValidatedAt}' ?: null,
        'billing_address' => json_decode('{$billingAddressJson}', true) ?: null,
    ]);

    // Initialize tenancy
    tenancy()->initialize(\$tenant);

    // Seed roles if not already seeded by TenantSeeder
    if (Spatie\Permission\Models\Role::count() === 0) {
        (new Database\Seeders\RolesAndPermissionsSeeder())->run();
    }

    // Seed standard data (parcours, phases, actions, templates, etc.)
    if (App\Models\Parcours::count() === 0) {
        (new Database\Seeders\DefaultDataSeeder())->run();
    }

    // Persist company_name as a setting so the setup wizard can pre-populate
    // step 1 with the value the user typed at signup. Without this, wizard.s.company_name
    // is empty and the admin has to retype the entreprise name they just gave.
    App\Models\CompanySetting::updateOrCreate(['key' => 'company_name'], ['value' => '{$companyName}']);

    // Create admin user
    \$user = App\Models\User::create([
        'name' => '{$adminName}',
        'email' => '{$adminEmail}',
        'password' => '{$passwordHashEscaped}',
    ]);
    \$user->assignRole('super_admin');

    // Create Sanctum token
    \$token = \$user->createToken('registration', ['*'], now()->addDays(30))->plainTextToken;

    // Output JSON result
    echo json_encode([
        'ok' => true,
        'tenant_id' => '{$tenantId}',
        'user_id' => \$user->id,
        'user_name' => \$user->name,
        'user_email' => \$user->email,
        'token' => \$token,
        'permissions' => \$user->getAllPermissions()->pluck('name')->toArray(),
    ]);
} catch (Exception \$e) {
    echo json_encode(['ok' => false, 'error' => \$e->getMessage()]);
}
SCRIPT
            );

            exec("{$phpBin} {$scriptPath} 2>&1", $output, $exitCode);
            @unlink($scriptPath);

            $jsonResult = null;
            foreach (array_reverse($output) as $line) {
                $decoded = json_decode($line, true);
                if ($decoded && isset($decoded['ok'])) { $jsonResult = $decoded; break; }
            }

            if (!$jsonResult || !$jsonResult['ok']) {
                $errMsg = $jsonResult['error'] ?? implode("\n", $output);
                throw new \Exception($errMsg);
            }

            // ── Welcome email (best-effort, non-blocking) ──
            try {
                $appUrl = config('app.frontend_url') ?: env('FRONTEND_URL', 'https://onboarding.illizeo.com');
                $tenantUrl = "{$appUrl}/{$jsonResult['tenant_id']}";
                \Illuminate\Support\Facades\Mail::to($jsonResult['user_email'])->send(
                    new \App\Mail\TenantWelcomeMail(
                        tenantId: $jsonResult['tenant_id'],
                        companyName: $request->company_name,
                        adminName: $jsonResult['user_name'],
                        adminEmail: $jsonResult['user_email'],
                        tenantUrl: $tenantUrl,
                    )
                );
            } catch (\Throwable $e) {
                \Log::warning("Welcome email failed for {$jsonResult['user_email']}: " . $e->getMessage());
            }

            return response()->json([
                'message' => 'Espace créé avec succès',
                'tenant_id' => $jsonResult['tenant_id'],
                'user' => [
                    'id' => $jsonResult['user_id'],
                    'name' => $jsonResult['user_name'],
                    'email' => $jsonResult['user_email'],
                    'roles' => ['super_admin'],
                    'permissions' => $jsonResult['permissions'],
                    'collaborateur_id' => null,
                ],
                'token' => $jsonResult['token'],
            ], 201);

        } catch (\Exception $e) {
            \Log::error("Tenant registration failed for {$tenantId}: " . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la création du compte: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if a tenant ID (slug) is available.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $request->validate(['company_name' => 'required|string']);
        $tenantId = Str::slug($request->company_name);
        $available = !Tenant::find($tenantId);

        return response()->json([
            'tenant_id' => $tenantId,
            'available' => $available,
        ]);
    }
}
