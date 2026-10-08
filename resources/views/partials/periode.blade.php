{{--
    Pemilih periode: dropdown Tahun · tombol bulan Jan–Des (satu bulan) · dropdown Bulan mulai & Bulan akhir.
    Variabel: $periode (App\Support\Periode), $rute (nama rute daftar), $bawa (parameter lain yang dipertahankan, mis. q, status).
--}}
@php($bawa = array_filter($bawa ?? [], fn ($v) => $v !== null && $v !== ''))
@php($namaBulan = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Illuminate\Support\Carbon::create(2000, $m, 1)->translatedFormat('M')]))
<form method="GET" action="{{ route($rute) }}" class="periode" id="form-periode">
    @foreach ($bawa as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
    <label class="periode-isian">Tahun
        <select name="tahun" id="periode-tahun">
            @foreach ($periode->daftarTahun as $t)
                <option value="{{ $t }}" @selected($t === $periode->tahun)>{{ $t }}</option>
            @endforeach
        </select>
    </label>
    <div class="periode-bulan">
        @foreach ($namaBulan as $m => $nama)
            <a href="{{ route($rute, $bawa + ['tahun' => $periode->tahun, 'dari' => $m, 'sampai' => $m]) }}"
               @class(['chip', 'aktif' => $periode->tunggal() && $periode->dari === $m, 'dalam' => ! $periode->tunggal() && $m >= $periode->dari && $m <= $periode->sampai,
                   'kosong' => ! in_array($m, $periode->bulanAda, true)])
               @unless (in_array($m, $periode->bulanAda, true)) title="Belum ada data" @endunless>{{ $nama }}</a>
        @endforeach
    </div>
    <label class="periode-isian">Bulan mulai
        <select name="dari" class="periode-rentang">
            @foreach ($namaBulan as $m => $nama)
                <option value="{{ $m }}" @selected($m === $periode->dari)>{{ $nama }}</option>
            @endforeach
        </select>
    </label>
    <label class="periode-isian">Bulan akhir
        <select name="sampai" class="periode-rentang">
            @foreach ($namaBulan as $m => $nama)
                <option value="{{ $m }}" @selected($m === $periode->sampai)>{{ $nama }}</option>
            @endforeach
        </select>
    </label>
</form>
<script>
    (() => {
        const f = document.getElementById('form-periode');
        // Ganti tahun → bulan dipilih otomatis (bulan terakhir yang ada datanya di tahun itu).
        document.getElementById('periode-tahun').addEventListener('change', () => {
            f.querySelectorAll('.periode-rentang').forEach(s => s.disabled = true);
            f.submit();
        });
        f.querySelectorAll('.periode-rentang').forEach(s => s.addEventListener('change', () => {
            const [dari, sampai] = [...f.querySelectorAll('.periode-rentang')];
            if (+dari.value > +sampai.value) (s === dari ? sampai : dari).value = s.value; // mulai tidak boleh sesudah akhir
            f.submit();
        }));
    })();
</script>
