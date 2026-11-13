<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

abstract class Controller
{
    /**
     * Phan trang ma trang vuot qua trang cuoi la 404, khong phai mot trang rong 200.
     *
     * Khong co chan nay thi moi gia tri page la mot URL hop le dai hang tram KB, va
     * crawler sinh URL moi vo han.
     *
     * Chi nhan so nguyen duong, dung bang nhung gi link phan trang that co the sinh
     * ra. Paginator ep -5, abc, 02, 2.5 va so tran ve trang 1 nen so sanh mot minh
     * no khong bat duoc: tung gia tri van la mot URL 200 moi, cung noi dung trang 1.
     */
    protected function paginateOr404(Builder $query, int $perPage): LengthAwarePaginator
    {
        // ?page[]=1 khong phai do link nao sinh va lam query builder vo.
        abort_if(is_array(request()->query('page')), 400);

        abort_if(
            request()->query('page') !== null
                && filter_var(request()->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false,
            404
        );

        $products = $query->paginate($perPage)->withQueryString();

        abort_if($products->currentPage() > $products->lastPage(), 404);

        return $products;
    }
}
