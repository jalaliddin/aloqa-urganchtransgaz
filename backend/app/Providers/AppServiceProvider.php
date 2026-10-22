<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Alert;
use App\Models\Approval;
use App\Models\CableLine;
use App\Models\CableMetalComposition;
use App\Models\CableSegment;
use App\Models\CableType;
use App\Models\DismantlingOrder;
use App\Models\Document;
use App\Models\GasPipelineAsset;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\MaterialItem;
use App\Models\MaterialReceipt;
use App\Models\MetalMassCalculation;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Models\WarehouseTransaction;
use App\Services\MetalMassCalculator;
use App\Support\Rbac;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Stable, short type names in polymorphic columns, audit log and API
        Relation::enforceMorphMap([
            'organization' => Organization::class,
            'user' => User::class,
            'gas_pipeline_asset' => GasPipelineAsset::class,
            'cable_line' => CableLine::class,
            'cable_segment' => CableSegment::class,
            'inspection' => Inspection::class,
            'inspection_item' => InspectionItem::class,
            'dismantling_order' => DismantlingOrder::class,
            'approval' => Approval::class,
            'material_receipt' => MaterialReceipt::class,
            'material_item' => MaterialItem::class,
            'warehouse_transaction' => WarehouseTransaction::class,
            'document' => Document::class,
            'setting' => Setting::class,
            'alert' => Alert::class,
            'cable_type' => CableType::class,
            'cable_metal_composition' => CableMetalComposition::class,
            'metal_mass_calculation' => MetalMassCalculation::class,
        ]);

        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Theoretical metal masses depend on the segment length: keep the calculation history
        CableSegment::updated(function (CableSegment $segment): void {
            if ($segment->wasChanged(['design_length_m', 'measured_length_m'])) {
                app(MetalMassCalculator::class)->recordLengthChange($segment);
            }
        });

        Gate::before(fn (User $user) => $user->hasRole(Rbac::SUPER_ADMIN) ? true : null);

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
