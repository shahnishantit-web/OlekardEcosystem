<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Services\ProviderRegistryService;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PaymentOptionsController extends Controller
{
    public function __construct(
        private readonly ProviderRegistryService $registry,
    ) {
    }

    /**
     * GET /v1/payment/options — returns available payment options for a given intent context.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel|null $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $purposeType      = $request->query('purpose_type', '');
        $purposeReference = $request->query('purpose_reference', '');
        $currencyCode     = $request->query('currency', 'BWP');
        $amountMinor      = (int) $request->query('amount', '0');
        $organisationId   = (string) ($request->query('organisation_id') ?? '');
        $countryCode      = (string) ($request->query('country_code') ?? '');
        $moduleKey        = (string) ($request->query('module_key') ?? '');

        if (empty($purposeType) || empty($purposeReference)) {
            return response()->json(['error' => 'purpose_type and purpose_reference are required'], 422);
        }

        $requiredCapability = (string) ($request->query('required_capability') ?? '');

        $query = new OptionsQuery(
            purposeType:        (string) $purposeType,
            purposeReference:   (string) $purposeReference,
            currencyCode:       (string) $currencyCode,
            amountMinor:        $amountMinor,
            organisationId:     $organisationId,
            actorId:            $user->id,
            countryCode:        $countryCode,
            moduleKey:          $moduleKey,
            requiredCapability: $requiredCapability !== '' ? $requiredCapability : null,
        );

        $options = $this->registry->discoverOptions($query);

        $serialized = array_map(static fn ($option) => [
            'provider_code'             => $option->providerCode,
            'display_name'              => $option->displayName,
            'display_label'             => $option->displayLabel ?? $option->displayName,
            'display_description'       => $option->displayDescription,
            'display_icon_key'          => $option->displayIconKey,
            'display_badge'             => $option->displayBadge,
            'is_wallet'                 => $option->isWallet,
            'is_default'                => $option->isDefault,
            'sort_order'                => $option->sortOrder,
            'display_order'             => $option->displayOrder,
            'wallet_balance_minor'      => $option->walletBalanceMinor,
            'has_sufficient_balance'    => $option->hasSufficientBalance,
            'checkout_flow'             => $option->checkoutFlow,
            'payment_method'            => $option->paymentMethod,
            'eligible_instrument_types' => $option->eligibleInstrumentTypes,
            'capability_flags'          => $option->capabilityFlags,
            'fee_disclosure'            => $option->feeDisclosure,
            'availability'              => [
                'restrictions' => $option->restrictions ?? [],
            ],
            'supported_next_action'     => $option->supportedNextAction,
        ], $options);

        return response()->json(['data' => $serialized]);
    }
}
