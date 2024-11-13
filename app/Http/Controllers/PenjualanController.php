<?php

namespace App\Http\Controllers;

use PDF;
use App\Models\Member;
use App\Models\Produk;
use App\Models\Setting;
use App\Models\Penjualan;
use Illuminate\Http\Request;
use App\Models\PenjualanDetail;

class PenjualanController extends Controller
{
    public function index()
    {
        return view('penjualan.index');
    }

    public function data()
    {
        $penjualan = Penjualan::with('member')->orderBy('id_penjualan', 'desc')->get();

        return datatables()
            ->of($penjualan)
            ->addIndexColumn()
            ->addColumn('total_item', function ($penjualan) {
                return format_uang($penjualan->total_item);
            })
            ->addColumn('total_harga', function ($penjualan) {
                return 'Rp. ' . format_uang($penjualan->total_harga);
            })
            ->addColumn('bayar', function ($penjualan) {
                return 'Rp. ' . format_uang($penjualan->bayar);
            })
            ->addColumn('tanggal', function ($penjualan) {
                return tanggal_indonesia($penjualan->created_at, false);
            })
            ->addColumn('kode_member', function ($penjualan) {
                $member = $penjualan->member->kode_member ?? '';
                return '<span class="label label-success">' . $member . '</span>';
            })
            ->editColumn('diskon', function ($penjualan) {
                return $penjualan->diskon . '%';
            })
            ->editColumn('kasir', function ($penjualan) {
                return $penjualan->user->name ?? '';
            })
            ->addColumn('aksi', function ($penjualan) {
                if (auth()->user()->level == 1) {
                    return '
                <div class="btn-group">
                    <button onclick="showDetail(`' . route('penjualan.show', $penjualan->id_penjualan) . '`)" class="btn btn-xs btn-info btn-flat"><i class="fa fa-eye"></i></button>
                    <button onclick="deleteData(`' . route('penjualan.destroy', $penjualan->id_penjualan) . '`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>
                </div>
                ';
                } else {
                    return '
                <div class="btn-group">
                    <button onclick="showDetail(`' . route('penjualan.show', $penjualan->id_penjualan) . '`)" class="btn btn-xs btn-info btn-flat"><i class="fa fa-eye"></i></button>
                </div>
                ';
                }
            })
            ->rawColumns(['aksi', 'kode_member'])
            ->make(true);
    }

    public function create()
    {
        $penjualan = new Penjualan();
        $penjualan->id_member = null;
        $penjualan->total_item = 0;
        $penjualan->total_harga = 0;
        $penjualan->diskon = 0;
        $penjualan->bayar = 0;
        $penjualan->diterima = 0;
        $penjualan->status = 0; // Set status sebagai draft
        $penjualan->id_user = auth()->id();
        $penjualan->save();

        session(['id_penjualan' => $penjualan->id_penjualan]);
        return redirect()->route('transaksi.index');
    }

    public function store(Request $request)
    {
        // Validasi input sebelum menyimpan
        $request->validate([
            'total_item' => 'required|numeric|min:1', // Memastikan total_item minimal 1
            'total' => 'required|numeric|min:1', // Memastikan total harga minimal 1
            'diskon' => 'nullable|numeric|min:0', // Diskon boleh 0
            'diterima' => 'required|numeric|min:0', // Diterima harus lebih besar dari atau sama dengan 0
        ]);

        $penjualan = Penjualan::findOrFail($request->id_penjualan);
        $penjualan->id_member = $request->id_member;
        $penjualan->total_item = $request->total_item;
        $penjualan->total_harga = $request->total;
        $penjualan->diskon = $request->diskon;
        $penjualan->bayar = $request->bayar;
        $penjualan->diterima = $request->diterima;

        // Pastikan total harga dan total item tidak 0
        if ($penjualan->total_item <= 0 || $penjualan->total_harga <= 0) {
            return redirect()->back()->withErrors(['error' => 'Total harga dan total item tidak boleh nol atau negatif.'])->withInput();
        }

        if ($request->diterima < $penjualan->total_harga) {
            // Jika diterima lebih kecil dari total, simpan sisa hutang
            $penjualan->hutang = $penjualan->total_harga - $request->diterima;
            $penjualan->status = 0; // Transaksi tetap draft
        } else {
            $penjualan->hutang = 0; // Tidak ada hutang jika diterima >= total
            $penjualan->status = 1; // Final
        }


        $detail = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
        foreach ($detail as $item) {
            $item->diskon = $request->diskon;
            $item->update();

            $produk = Produk::find($item->id_produk);
            $produk->stok -= $item->jumlah;
            $produk->update();
        }

        $penjualan->update();
        session()->forget('id_penjualan');
        return redirect()->route('transaksi.index');
    }

    public function update(Request $request, $id)
    {
        $penjualan = Penjualan::find($id);
        $totalHarga = $penjualan->total_harga;
        $diterima = $request->input('diterima');

        if ($diterima < $totalHarga) {
            $penjualan->hutang = $totalHarga - $diterima;
            $penjualan->status = 0; // Tetap dalam status Draft
        } else {
            $penjualan->hutang = 0; // Tidak ada hutang
            $penjualan->status = 1; // Status menjadi Final
        }

        $penjualan->diterima = $diterima;
        $penjualan->bayar = min($totalHarga, $diterima); // Menyimpan jumlah yang telah dibayar
        $penjualan->save();

        return response()->json(['success' => true]);
    }

    public function updateProduct(Request $request, $id_penjualan)
    {
        $penjualan = Penjualan::findOrFail($id_penjualan);

        // Cari apakah produk sudah ada dalam transaksi
        $detail = $penjualan->details()->where('id_produk', $request->id_produk)->first();

        if ($detail) {
            // Update quantity jika produk sudah ada
            $detail->quantity += $request->quantity;
            $detail->subtotal = $detail->quantity * $detail->harga;
            $detail->save();
        } else {
            // Tambahkan produk baru jika belum ada
            $penjualan->details()->create([
                'id_produk' => $request->id_produk,
                'quantity' => $request->quantity,
                'harga' => Produk::find($request->id_produk)->harga,
                'subtotal' => $request->quantity * Produk::find($request->id_produk)->harga
            ]);
        }

        // Update total harga transaksi
        $penjualan->total_harga = $penjualan->details->sum('subtotal');
        $penjualan->save();

        return response()->json(['status' => 'success', 'message' => 'Produk berhasil diperbarui']);
    }

    public function getDraftTransaction($id_penjualan)
    {
        // Ambil data transaksi beserta detail produk yang terkait
        $penjualan = Penjualan::with(['details.produk', 'member'])
            ->where('id_penjualan', $id_penjualan)
            ->where('status', 0) // 0 untuk status draft
            ->firstOrFail();

        $produk = Produk::orderBy('nama_produk')->get();
        $member = Member::orderBy('nama')->get();
        $memberSelected = $penjualan->member ?? new Member();
        $diskon = Setting::first()->diskon ?? 0;
        $drafts = Penjualan::where('status', 0)->get();

        // Kirimkan juga id_penjualan ke view
        return view('penjualan_detail.update', compact('penjualan', 'id_penjualan', 'produk', 'drafts', 'member', 'diskon', 'memberSelected'));
    }



    public function bayarHutang(Request $request, $id)
    {
        $penjualan = Penjualan::find($id);
        $bayar = $request->input('bayar');

        if ($bayar >= $penjualan->hutang) {
            $penjualan->bayar += $penjualan->hutang;
            $penjualan->hutang = 0;
            $penjualan->status = 1; // Transaksi final setelah hutang lunas
        } else {
            $penjualan->bayar += $bayar;
            $penjualan->hutang -= $bayar;
        }

        $penjualan->save();

        return response()->json(['success' => true]);
    }

    public function showDrafts()
    {
        // Ambil semua transaksi yang berstatus draft (status = 0)
        $drafts = Penjualan::where('status', 0)->get();

        // Tampilkan view dan kirimkan data draft
        return view('penjualan_detail.draft', compact('drafts'));
    }

    public function updateDraft(Request $request)
    {
        // Pastikan draft sesuai dengan session yang aktif
        $id_penjualan = session('id_penjualan');
        $penjualan = Penjualan::findOrFail($id_penjualan);

        $penjualan->id_member = $request->id_member ?? $penjualan->id_member;
        $penjualan->total_item = $request->total_item ?? $penjualan->total_item;
        $penjualan->total_harga = $request->total_harga ?? $penjualan->total_harga;
        $penjualan->bayar = $request->bayar ?? $penjualan->bayar;
        $penjualan->diterima = $request->diterima ?? $penjualan->diterima;
        $penjualan->diskon = $request->diskon ?? $penjualan->diskon;

        $penjualan->save();

        return response()->json(['status' => 'Draft updated successfully']);
    }


    public function show($id)
    {
        $detail = PenjualanDetail::with('produk')->where('id_penjualan', $id)->get();

        return datatables()
            ->of($detail)
            ->addIndexColumn()
            ->addColumn('kode_produk', function ($detail) {
                return '<span class="label label-success">' . $detail->produk->kode_produk . '</span>';
            })
            ->addColumn('nama_produk', function ($detail) {
                return $detail->produk->nama_produk;
            })
            ->addColumn('harga_jual', function ($detail) {
                return 'Rp. ' . format_uang($detail->harga_jual);
            })
            ->addColumn('jumlah', function ($detail) {
                return format_uang($detail->jumlah);
            })
            ->addColumn('subtotal', function ($detail) {
                return 'Rp. ' . format_uang($detail->subtotal);
            })
            ->rawColumns(['kode_produk'])
            ->make(true);
    }

    public function destroy($id)
    {
        $penjualan = Penjualan::find($id);
        $detail    = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
        foreach ($detail as $item) {
            $produk = Produk::find($item->id_produk);
            if ($produk) {
                $produk->stok += $item->jumlah;
                $produk->update();
            }

            $item->delete();
        }

        $penjualan->delete();

        return response(null, 204);
    }

    public function selesai()
    {
        $setting = Setting::first();

        return view('penjualan.selesai', compact('setting'));
    }

    public function notaKecil()
    {
        $setting = Setting::first();
        $penjualan = Penjualan::find(session('id_penjualan'));
        if (! $penjualan) {
            abort(404);
        }
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', session('id_penjualan'))
            ->get();

        return view('penjualan.nota_kecil', compact('setting', 'penjualan', 'detail'));
    }

    public function notaBesar()
    {
        $setting = Setting::first();
        $penjualan = Penjualan::find(session('id_penjualan'));
        if (! $penjualan) {
            abort(404);
        }
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', session('id_penjualan'))
            ->get();

        $pdf = PDF::loadView('penjualan.nota_besar', compact('setting', 'penjualan', 'detail'));
        $pdf->setPaper(0, 0, 609, 440, 'potrait');
        return $pdf->stream('Transaksi-' . date('Y-m-d-his') . '.pdf');
    }
}
