{{-- Baris kecil di bawah nama tahap: surveyor & lokasi (dari Daftar Proyek), supaya jelas tahap itu di mana. --}}
@if ($t->surveyor || $t->lokasi)
    <div class="sub-tahap" style="font-size: 11.5px; color: var(--redup); margin-top: 2px; font-weight: 400;">
        @if ($t->lokasi)📍 {{ $t->lokasi }}@endif
        @if ($t->surveyor && $t->lokasi) · @endif
        @if ($t->surveyor)Surveyor {{ $t->surveyor }}@endif
    </div>
@endif
