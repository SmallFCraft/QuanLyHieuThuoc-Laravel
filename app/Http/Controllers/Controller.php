<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

abstract class Controller
{
    /**
     * Phân trang mà trang vượt quá trang cuối là 404, không phải một trang rỗng 200.
     *
     * Không có chặn này thì mỗi giá trị page là một URL hợp lệ dài hàng trăm KB, và
     * crawler sinh URL mới vô hạn.
     *
     * Chỉ nhận số nguyên dương, đúng bằng những gì link phân trang thật có thể sinh
     * ra. Paginator ép -5, abc, 02, 2.5 và số tràn về trang 1 nên so sánh một mình
     * nó không bắt được: từng giá trị vẫn là một URL 200 mới, cùng nội dung trang 1.
     */
    protected function paginateOr404(Builder $query, int $perPage): LengthAwarePaginator
    {
        // ?page[]=1 không phải do link nào sinh và làm query builder vỡ.
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
