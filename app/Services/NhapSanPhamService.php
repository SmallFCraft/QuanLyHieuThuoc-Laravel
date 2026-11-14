<?php

namespace App\Services;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Support\LongChauProduct;
use App\Support\VietnameseText;
use Illuminate\Support\Facades\DB;

/**
 * Ghi du lieu catalog crawled vao san_phams va danh_mucs.
 *
 * Hai lop du lieu gap nhau tai day. Cac field catalog (ten, gia, thanh phan, cach dung,
 * anh, danh muc) thuoc ve nguon va duoc lam moi moi lan crawl. Cac field ton kho
 * (so lo, han dung, so luong) thuoc ve nha thuoc: lan nhap dau tien seed mot gia tri
 * cho de dong hoan chinh, va cac lan nhap sau de nguyen nhung gi nhan vien da nhap.
 */
class NhapSanPhamService
{
    /** Ton kho cho truoc, chi duoc ghi khi san pham duoc tao lan dau. */
    private const SEED_STOCK_MIN = 0;
    private const SEED_STOCK_MAX = 200;
    private const SEED_REORDER_MIN = 10;
    private const SEED_REORDER_MAX = 30;

    /** @var array<string, int> ten danh muc da fold => ma_dm, tranh mot lookup cho moi san pham */
    private array $maDanhMuc = [];

    /** @var array<string> ten hien thi cua cac danh muc lan chay nay phai tao */
    private array $danhMucMoi = [];

    /**
     * Tao hoac lam moi mot san pham. An toan de goi nhieu lan cho cung ban ghi nguon:
     * no phan giai ve cung mot dong.
     */
    public function nhap(LongChauProduct $source): SanPham
    {
        return DB::transaction(function () use ($source) {
            $categoryId = $this->phanGiaiMaDanhMuc($source->categoryName);
            $batchNumber = $source->batchNumber();

            $sanPham = SanPham::withTrashed()->firstWhere('so_lo', $batchNumber);

            if ($sanPham === null) {
                return $this->tao($source, $categoryId, $batchNumber);
            }

            return $this->lamMoi($sanPham, $source, $categoryId);
        });
    }

    /**
     * Reset cache danh muc theo tung lan chay. Cac lan crawl dai neu khong se giu
     * nhung id ma quan tri vien co the da xoa giua lan chay.
     */
    public function quenDanhMuc(): void
    {
        $this->maDanhMuc = [];
        $this->danhMucMoi = [];
    }

    /**
     * Ten hien thi cua cac danh muc lan chay nay da tao, de lenh co the bao cao
     * chung. Mot lan crawl am tham tao danh muc la nguyen nhan dau tien lam catalog
     * drift thanh hai dong "Co xuong khop".
     *
     * @return array<string>
     */
    public function danhMucMoi(): array
    {
        return array_values($this->danhMucMoi);
    }

    private function tao(LongChauProduct $source, int $categoryId, string $batchNumber): SanPham
    {
        // ProductObserver::created() doc so_lo va ngay_het_han de dung dong lo_san_phams
        // tuong ung, nen ca hai phai duoc gan khi insert.
        return SanPham::create([
            'ma_dm' => $categoryId,
            'ten' => $source->name,
            'thanh_phan' => $source->ingredients,
            'cach_dung' => $source->usage,
            'han_dung_thang' => $source->shelfLifeMonths,
            'gia' => $source->price,
            'hinh_anh' => $source->imageUrl,
            'so_lo' => $batchNumber,
            'ngay_het_han' => $source->expirationDate(),
            'so_luong_ton' => random_int(self::SEED_STOCK_MIN, self::SEED_STOCK_MAX),
            'muc_canh_bao' => random_int(self::SEED_REORDER_MIN, self::SEED_REORDER_MAX),
        ]);
    }

    /**
     * Chi cac field catalog. So luong ton kho, diem dat lai hang va ngay het han duoc
     * de nguyen vi nha thuoc co the da sua chung tu lan crawl truoc, va ghi de ton kho
     * that bang mot con so ngau nhien se lam hong FEFO.
     */
    private function lamMoi(SanPham $sanPham, LongChauProduct $source, int $categoryId): SanPham
    {
        if ($sanPham->trashed()) {
            $sanPham->restore();
        }

        $sanPham->fill([
            'ma_dm' => $categoryId,
            'ten' => $source->name,
            'thanh_phan' => $source->ingredients,
            'cach_dung' => $source->usage,
            'han_dung_thang' => $source->shelfLifeMonths,
            'gia' => $source->price,
            'hinh_anh' => $source->imageUrl,
        ])->save();

        return $sanPham;
    }

    /**
     * Danh muc den tu breadcrumb cua chinh Long Chau. Khop tren ten da fold, chu khong
     * phai ten tho: nguon phat hanh cung breadcrumb cap 2 nhu ca hai "Co - xuong - khop"
     * (thuoc Thuoc) va "Co xuong khop" (thuoc Thuc pham chuc
     * nang), va khop theo ten tho da lam crawl tao mot danh muc cho moi cach viet, dieu
     * do chia 114 san pham thanh hai dong tren storefront ma khach doc nhu mot.
     *
     * Cach viet dau tien duoc thay giu ten hien thi; DanhMuc::booted() dien ma
     * va cot folded.
     */
    private function phanGiaiMaDanhMuc(string $name): int
    {
        $folded = VietnameseText::fold($name);

        if (isset($this->maDanhMuc[$folded])) {
            return $this->maDanhMuc[$folded];
        }

        $danhMuc = DanhMuc::firstOrCreate(['ten_khong_dau' => $folded], ['ten' => $name]);

        if ($danhMuc->wasRecentlyCreated) {
            $this->danhMucMoi[$folded] = $danhMuc->ten;
        }

        return $this->maDanhMuc[$folded] = (int) $danhMuc->ma_dm;
    }
}
