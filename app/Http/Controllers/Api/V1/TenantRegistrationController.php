<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
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
        ]);

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
            $scriptPath = base_path("_reg_{$tenantId}.php");
            $companyName = addslashes($request->company_name);
            $adminName = addslashes($request->admin_name);
            $adminEmail = addslashes($request->admin_email);
            $passwordHash = Hash::make($request->password);
            $passwordHashEscaped = addslashes($passwordHash);

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
    ]);

    // Initialize tenancy
    tenancy()->initialize(\$tenant);

    // Seed roles if not already seeded by TenantSeeder
    if (Spatie\Permission\Models\Role::count() === 0) {
        (new Database\Seeders\RolesAndPermissionsSeeder())->run();
    }

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
