<?php

namespace App\Http\Controllers;

use App\Models\Master;
use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(private ReferralService $referrals)
    {
    }

    public function attach(Request $request): JsonResponse
    {
        $master = $this->currentMaster($request);
        $data = $request->validate(['code' => ['required', 'string']]);

        $referrer = Master::where('referral_code', $data['code'])->first();

        if (!$referrer) {
            return response()->json(['error' => 'Реферальный код не найден'], 404);
        }

        if ($referrer->id === $master->id) {
            return response()->json(['error' => 'Нельзя закрепиться за собой'], 422);
        }

        $existing = Referral::where('referred_master_id', $master->id)->first();

        if ($existing) {
            if ($existing->referrer_master_id !== $referrer->id) {
                return response()->json(['error' => 'Вы уже закреплены за другим мастером'], 409);
            }

            return response()->json([
                'attached' => true,
                'already_attached' => true,
                'referrer' => ['id' => $referrer->id, 'name' => $referrer->name],
                'attached_at' => $existing->created_at,
            ]);
        }

        $referral = $this->referrals->registerReferral($master, $data['code']);

        return response()->json([
            'attached' => true,
            'already_attached' => false,
            'referrer' => ['id' => $referrer->id, 'name' => $referrer->name],
            'attached_at' => $referral->created_at,
        ], 201);
    }

    public function my(Request $request): JsonResponse
    {
        $master = $this->currentMaster($request);

        $earned = ReferralEarning::where('referrer_master_id', $master->id)
            ->selectRaw('referral_id, SUM(amount) as total')
            ->groupBy('referral_id')
            ->pluck('total', 'referral_id');

        $items = $master->referrals()
            ->with('referredMaster:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Referral $r) => [
                'master_id' => $r->referred_master_id,
                'name' => $r->referredMaster->name,
                'attached_at' => $r->created_at,
                'rewarded' => $r->status === Referral::STATUS_REWARDED,
                'earned' => (int) ($earned[$r->id] ?? 0),
            ]);

        return response()->json(['data' => $items]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $master = $this->currentMaster($request);

        $sums = ReferralEarning::where('referrer_master_id', $master->id)
            ->selectRaw('status, SUM(amount) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'total' => (int) $sums->sum(),
            'pending' => (int) ($sums[ReferralEarning::STATUS_PENDING] ?? 0),
            'paid' => (int) ($sums[ReferralEarning::STATUS_PAID] ?? 0),
            'rewarded_referrals' => $master->referrals()->active()->count(),
            'total_referrals' => $master->referrals()->count(),
        ]);
    }

    private function currentMaster(Request $request): Master
    {
        $master = $request->attributes->get('current_master');

        abort_if(!$master, 401, 'Передайте корректный X-Master-Id');

        return $master;
    }
}
