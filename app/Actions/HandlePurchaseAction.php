<?php

namespace App\Actions;

use App\Models\Purchasable;
use App\Models\Purchase;
use App\Models\Referrer;
use App\Models\User;
use App\Services\GitHub\GitHubApi;
use App\Support\Paddle\PaddlePayload;
use Laravel\Paddle\Receipt;

class HandlePurchaseAction
{
    protected HandlePurchaseLicensingAction $handlePurchaseLicensingAction;
    protected RestoreRepositoryAccessAction $restoreRepositoryAccessAction;
    protected StartOrExtendNextPurchaseDiscountPeriodAction $startOrExtendExtraDiscountPeriodAction;
    protected AddPurchasedTagsToEmailListSubscriberAction $addPurchasedTagsToEmailListSubscriberAction;
    protected AttributePurchaseToReferrer $attributePurchaseToReferrerAction;
    protected GitHubApi $gitHubApi;

    public function __construct(
        HandlePurchaseLicensingAction $handlePurchaseLicensingAction,
        RestoreRepositoryAccessAction $restoreRepositoryAccessAction,
        StartOrExtendNextPurchaseDiscountPeriodAction $startOrExtendExtraDiscountPeriodAction,
        AddPurchasedTagsToEmailListSubscriberAction $addPurchasedTagsToEmailListSubscriberAction,
        AttributePurchaseToReferrer $attributePurchaseToReferrerAction,
        GitHubApi $gitHubApi
    ) {
        $this->handlePurchaseLicensingAction = $handlePurchaseLicensingAction;
        $this->restoreRepositoryAccessAction = $restoreRepositoryAccessAction;
        $this->startOrExtendExtraDiscountPeriodAction = $startOrExtendExtraDiscountPeriodAction;
        $this->addPurchasedTagsToEmailListSubscriberAction = $addPurchasedTagsToEmailListSubscriberAction;
        $this->attributePurchaseToReferrerAction = $attributePurchaseToReferrerAction;
        $this->gitHubApi = $gitHubApi;
    }

    public function execute(
        User $user,
        Purchasable $purchasable,
        PaddlePayload $paddlePayload,
        ?Referrer $referrer = null
    ): Purchase {
        $purchase = $this->createPurchase($user, $purchasable, $paddlePayload);

        $this->startOrExtendExtraDiscountPeriodAction->execute($user);

        $this->addPurchasedTagsToEmailListSubscriberAction->execute($purchase);

        $purchase = $this->handlePurchaseLicensingAction->execute($purchase);

        if ($purchasable->repository_access && $user->github_username) {
            $this->restoreRepositoryAccessAction->execute($user);
        }

        if ($referrer) {
            $this->attributePurchaseToReferrerAction->execute($purchase, $referrer);
        }

        return $purchase;
    }

    protected function createPurchase(User $user, Purchasable $purchasable, PaddlePayload $paddlePayload): Purchase
    {
        $receipt = Receipt::where('order_id', $paddlePayload->order_id)->first();

        return Purchase::create([
            'license_id' => null,
            'user_id' => $user->id,
            'purchasable_id' => $purchasable->id,
            'receipt_id' => $receipt->id,
            'paddle_webhook_payload' => $paddlePayload->toArray(),
            'paddle_fee' => $paddlePayload->balance_fee,
            'earnings' => $paddlePayload->balance_earnings,
        ]);
    }
}
