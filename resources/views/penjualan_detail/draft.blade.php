<div class="modal fade" id="modal-draft-transaksi" tabindex="-1" role="dialog" aria-labelledby="modal-draft-transaksi">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                        aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Daftar Draft Transaksi</h4>
            </div>
            <div class="modal-body">
                <table class="table table-striped table-bordered table-produk">
                    <thead>
                        <th width="5%">No</th>
                        <th>ID Member</th>
                        <th>Total Item</th>
                        <th>Total Belum Terbayar</th>
                        <th>Tanggal</th>
                        <th><i class="fa fa-cog"></i></th>
                    </thead>
                    <tbody>
                        @foreach ($drafts as $key => $item)
                            @if ($item->total_harga > 0)
                                <tr>
                                    <td width="5%">{{ $key + 1 }}</td>
                                    <td><span class="label label-success">{{ $item->id_member }}</span></td>
                                    <td>{{ $item->total_item }}</td>
                                    <td>{{ $item->total_harga }}</td>
                                    <td>{{ $item->created_at }}</td>
                    
                                    <td>
                                        <form action="{{ route('transaksi.update', $item->id_penjualan) }}" method="POST" style="display: inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-primary btn-xs btn-flat">
                                                <i class="fa fa-check-circle"></i> Pilih
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
