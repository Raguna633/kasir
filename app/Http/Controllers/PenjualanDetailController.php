<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Produk;
use App\Models\Setting;
use Illuminate\Http\Request;

class PenjualanDetailController extends Controller
{
    public function index()
    {
        $produk = Produk::orderBy('nama_produk')->get();
        $member = Member::orderBy('nama')->get();
        $diskon = Setting::first()->diskon ?? 0;
        // Ambil semua transaksi yang berstatus draft (status = 0)
        $drafts = Penjualan::where('status', 0)->get();

        // Cek apakah ada transaksi yang sedang berjalan
        if ($id_penjualan = session('id_penjualan')) {
            $penjualan = Penjualan::find($id_penjualan);
            $memberSelected = $penjualan->member ?? new Member();

            return view('penjualan_detail.index', compact('produk', 'member', 'diskon', 'id_penjualan', 'penjualan', 'memberSelected', 'drafts'));
        } else {
                return redirect()->route('transaksi.baru');
        }
    }

    public function data($id)
    {
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', $id)
            ->get();

        $data = array();
        $total = 0;
        $total_item = 0;

        foreach ($detail as $item) {
            $row = array();
            $row['kode_produk'] = '<span class="label label-success">' . $item->produk['kode_produk'] . '</span';
            $row['nama_produk'] = $item->produk['nama_produk'];
            $row['harga_jual']  = 'Rp. ' . format_uang($item->harga_jual);
            $row['jumlah']      = '<input type="number" class="form-control input-sm quantity" data-id="' . $item->id_penjualan_detail . '" value="' . $item->jumlah . '">';
            $row['max']         = $item->produk->stok;
            $row['diskon']      = $item->diskon . '%';
            $row['subtotal']    = 'Rp. ' . format_uang($item->subtotal);
            $row['aksi']        = '<div class="btn-group">
                                    <button onclick="deleteData(`' . route('transaksi.destroy', $item->id_penjualan_detail) . '`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>
                                </div>';
            $data[] = $row;

            $total += $item->harga_jual * $item->jumlah;
            $total_item += $item->jumlah;
        }
        $data[] = [
            'kode_produk' => '
                <div class="total hide">' . $total . '</div>
                <div class="total_item hide">' . $total_item . '</div>',
            'nama_produk' => '',
            'harga_jual'  => '',
            'jumlah'      => '',
            'max'         => '',
            'diskon'      => '',
            'subtotal'    => '',
            'aksi'        => '',
        ];

        return datatables()
            ->of($data)
            ->addIndexColumn()
            ->rawColumns(['aksi', 'kode_produk', 'jumlah'])
            ->make(true);
    }

    public function store(Request $request)
    {
        $produk = Produk::where('id_produk', $request->id_produk)->first();
        if (!$produk) {
            return response()->json('Produk tidak ditemukan', 400);
        }

        // Cek apakah produk sudah ada dalam detail transaksi
        $detail = PenjualanDetail::where('id_penjualan', $request->id_penjualan)
            ->where('id_produk', $request->id_produk)
            ->first();

        if ($detail) {
            // Jika produk sudah ada, tambahkan jumlahnya
            $newQuantity = $detail->jumlah + 1;

            if ($newQuantity > $produk->stok) {
                return response()->json(['message' => 'Jumlah melebihi stok yang tersedia'], 400);
            }

            $detail->jumlah = $newQuantity;
            $detail->subtotal = $detail->harga_jual * $newQuantity;
            $detail->save();
        } else {
            if (1 > $produk->stok) {
                return response()->json(['message' => 'Stok produk tidak mencukupi'], 400);
            }

            $detail = new PenjualanDetail();
            $detail->id_penjualan = $request->id_penjualan;
            $detail->id_produk = $produk->id_produk;
            $detail->harga_jual = $produk->harga_jual;
            $detail->jumlah = 1;
            $detail->diskon = 0;
            $detail->subtotal = $produk->harga_jual;
            $detail->status = '1';
            $detail->save();
        }

        return response()->json('Data berhasil disimpan', 200);
    }

    public function update(Request $request, $id)
    {
        $detail = PenjualanDetail::find($id);
        $produk = Produk::find($detail->id_produk);

        if ($request->jumlah > $produk->stok) {
            return response()->json(['message' => 'Jumlah melebihi stok yang tersedia'], 400);
        }

        $detail->jumlah = $request->jumlah;
        $detail->subtotal = $detail->harga_jual * $request->jumlah;
        $detail->save();

        return response()->json('Data berhasil diperbarui', 200);
    }


    public function destroy($id)
    {
        $detail = PenjualanDetail::find($id);
        $detail->delete();

        return response(null, 204);
    }

    public function loadForm($diskon = 0, $total = 0, $diterima = 0)
    {
        $bayar   = $total - ($diskon / 100 * $total);
        $kembali = ($diterima != 0) ? $diterima - $bayar : 0;
        $data    = [
            'totalrp' => format_uang($total),
            'bayar' => $bayar,
            'bayarrp' => format_uang($bayar),
            'terbilang' => ucwords(terbilang($bayar) . ' Rupiah'),
            'kembalirp' => format_uang($kembali),
            'kembali_terbilang' => ucwords(terbilang($kembali) . ' Rupiah'),
        ];

        return response()->json($data);
    }
}
