@extends('layouts.publik')

@section('judul', 'Heavy Equipment Rental & Earthwork Contractor')

{{-- Company profile (sumber: "Company Profile - MNP Multi Niaga Putra-2025 [Revisi 2].pdf", owner 9 Nov 2026). Salinan izin usaha sengaja tidak dipasang (berisi data pribadi). --}}
@php
    $sektor = ['MNG' => 'Mining', 'CON' => 'Construction', 'FOR' => 'Forestry', 'AGR' => 'Agro'];
    $proyek = [
        ['Antareja Mahada Makmur', 'MNG', "Dec '23 – Now", 'Mining Contracting', 'Samarinda', 'Rental'],
        ['Badan Kerjasama Mutiara Buana', 'CON', "Jul '24 – Now", 'Pantai Mutiara', 'North Jakarta', 'Civil Engineering'],
        ['Badan Mahakarya Sejahtra Pangan', 'CON', "Jul '24 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Badan Mandiri Bangun Makmur', 'CON', "Jul '24 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Bangun Karya Persada Nusantara', 'CON', "Jan '20 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Bangun Kosambi Sukses Tbk', 'CON', "Jul '24 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Baratama Putra Perkasa', 'FOR', "Dec '22 – Oct '23", 'Forestry Sectoral Contracting', 'Sampit', 'Contracting'],
        ['Barito Teknik Prasarana', 'CON', "Mar '23 – Now", 'Hauling Road Contracting', 'Buhut', 'Rental'],
        ['Berau Usaha Mandiri', 'MNG', "Jun '22 – Dec '22", 'Mining Contracting', 'Berau', 'Rental'],
        ['Beton Elemenindo Perkasa', 'CON', "Jan '20 – Jan '21", 'Concrete Sand Material Supply', 'Batujajar', 'Rental'],
        ['Bumindo Mekar Wibawa', 'CON', "Jul '24 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Cahaya Gemilang Indah Cemerlang', 'CON', "Jul '24 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Darussalam Sabilal Nusantara', 'MNG', "Dec '22 – Oct '23", 'Mining Contracting', 'Banjarmasin', 'OB Removal'],
        ['Kapuas Bara Utama', 'MNG', "Dec '23 – Feb '24", 'Hauling Road Contracting', 'Buhut', 'Contracting'],
        ['Karunia Utama Selaras', 'CON', "Jul '24 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Karya Selaras Parahyangan', 'CON', "Jan '20 – Jan '22", 'Sand Quarry', 'Batujajar', 'Rental'],
        ['Kemilau Karya Utama', 'CON', "Jul '24 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Mega Andalan Sukses', 'CON', "Mar '24 – Now", 'PIK Extension', 'Tangerang', 'Civil Engineering'],
        ['Mega Andalan Sukses', 'CON', "Jul '24 – Now", 'PIK 2 Extension', 'Tangerang', 'Civil Engineering'],
        ['Meratus Borneo Sakti', 'MNG', "Dec '22 – Oct '23", 'Hauling Road Contracting', 'Buhut', 'Contracting'],
        ['Orecon Sadanus Perkasa', 'MNG', "Jan '24 – Now", 'Heavy Equipment Rent', 'Berau', 'Rental'],
        ['Perdana Karya Perkasa', 'CON', "Jun '23 – Mar '24", 'Hauling Road Contracting', 'Buhut', 'Contracting'],
        ['Perdana Karya Perkasa Tbk.', 'MNG', "Jan '24 – Mar '24", 'Hauling Road Contracting', 'Buhut', 'Rental'],
        ['Petrosea Tbk.', 'MNG', "May '24 – Dec '24", 'Heavy Equipment Rent', 'Buhut', 'Rental'],
        ['Putra Muba Coal', 'MNG', "Feb '24 – Dec '24", 'Mining Contracting', 'Jambi', 'Rental'],
        ['Satria Alam Manunggal', 'MNG', "Dec '23 – Mar '24", 'Hauling Road Contracting', 'Buhut', 'Contracting'],
        ['SCG–WIKA/Waskita J/O', 'CON', "Dec '19 – Feb '21", 'Toll Cisumdawu Section II', 'Cisumdawu', 'Rental'],
        ['Surya Hutani Jaya', 'FOR', "May '23 – Dec '23", 'Forestry Sectoral Contracting', 'Samarinda', 'Contracting'],
        ['Wana Catur Jaya Utama', 'AGR', "Feb '24 – Now", 'Agro Sectoral Contracting', 'Pujon', 'Contracting'],
        ['Yayasan Univ. Pelita Harapan', 'CON', "Mar '24 – Now", 'PIK Extension', 'Tangerang', 'Civil Engineering'],
    ];
    $jumlahSektor = collect($proyek)->countBy(fn ($p) => $p[1]);
    $jumlahKlien = collect($proyek)->pluck(0)->unique()->count();
    $tonggak = [
        ['Feb 2019', 'Established', 'Started with dump truck and excavator rental at the Pantai Indah Kapuk projects, Jakarta.'],
        ['Dec 2020', 'Renting Services', 'Began exploring mining and coal projects outside Java — Kalimantan and Sulawesi.'],
        ['Jun 2021', 'Expansion', 'Focused on land clearing projects and gained valuable experience with Sinar Mas.'],
        ['May 2022', 'Team Up', 'Opened a new mining & rental sector with Barito Teknik Prasarana in Central Kalimantan.'],
        ['May 2023', 'Next Scale Up', 'Grew our projects with national-class companies such as DPC and PPA.'],
        ['Jan 2024', 'Forestry Growth', 'Expanded the forestry sector at Surya Hutani Jaya, East Kalimantan.'],
        ['Mar 2024', 'Agro Growth', 'Canaling, land preparation, land cultivation and equipment rental for agro estates.'],
        ['Jun 2024', 'Construction Growth', 'Cut & fill and landscaping for Lippo Group (UPH project) and the PIK project.'],
        ['Jul 2024', 'Land Cut & Fill', 'Started land cut & fill projects for Agung Sedayu Group.'],
        ['Next', 'Construction Services', 'Developing projects with national-class companies across all sectors — mining, coal, forestry, agro and construction.'],
    ];
    $izin = [
        ['Deed of Establishment', 'Akta Pendirian Perusahaan'],
        ['SK Menkumham', 'Ministry of Law & Human Rights approval'],
        ['NIB', 'Business Identification Number (OSS)'],
        ['SPPKP', 'Registered VAT-able entrepreneur'],
        ['SK-IUJP', 'Mining Services Business License'],
        ['Business License 1', 'Izin Usaha'],
        ['Business License 2', 'Izin Usaha'],
        ['SKT', 'Registered Certificate (Surat Keterangan Terdaftar)'],
    ];
@endphp

@push('gaya')
    <style>
        section { padding: 88px 0; }
        p.label-bagian, .label-bagian { display: inline-flex; align-items: center; gap: 8px; color: var(--merah); font-family: var(--judul); font-weight: 600; font-size: 13px; letter-spacing: .12em; text-transform: uppercase; margin: 0 0 12px; }
        .label-bagian::before { content: ''; width: 24px; height: 3px; background: var(--merah); border-radius: 2px; }
        h2.judul-bagian { font-size: clamp(28px, 3.6vw, 40px); margin: 0 0 16px; letter-spacing: -.02em; }
        .pengantar { color: var(--redup); font-size: 17px; max-width: 640px; margin: 0; }

        /* Pembuka */
        .pembuka { position: relative; min-height: min(86vh, 760px); display: flex; align-items: flex-end; padding: 0; color: #fff; overflow: hidden; background: var(--gelap); }
        .pembuka > img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .pembuka::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(14, 16, 19, .92) 0%, rgba(14, 16, 19, .72) 45%, rgba(14, 16, 19, .25) 100%), linear-gradient(0deg, rgba(14, 16, 19, .85) 0%, transparent 45%); }
        .pembuka .bungkus { position: relative; z-index: 1; width: 100%; padding-top: 120px; padding-bottom: 56px; }
        .pembuka .label-bagian { color: #ff8a7e; }
        .pembuka .label-bagian::before { background: #ff8a7e; }
        .pembuka h1 { color: #fff; font-size: clamp(36px, 5.6vw, 64px); letter-spacing: -.025em; margin: 0 0 18px; max-width: 780px; }
        .pembuka h1 em { font-style: normal; color: #ff6b5c; }
        .pembuka p.isi { font-size: clamp(16px, 1.6vw, 19px); color: #dfe3e8; max-width: 600px; margin: 0 0 30px; }
        .pembuka .aksi { display: flex; flex-wrap: wrap; gap: 12px; }
        .angka { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: rgba(255, 255, 255, .14); border: 1px solid rgba(255, 255, 255, .14); border-radius: 14px; overflow: hidden; margin-top: 56px; backdrop-filter: blur(6px); }
        .angka div { background: rgba(14, 16, 19, .55); padding: 18px 20px; }
        .angka b { display: block; font-family: var(--judul); font-size: clamp(24px, 2.6vw, 32px); font-weight: 700; color: #fff; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .angka span { font-size: 13.5px; color: #c4cad2; }
        @media (max-width: 720px) { .angka { grid-template-columns: repeat(2, 1fr); } }

        /* Tentang */
        .tentang .kisi { display: grid; grid-template-columns: 1.05fr 1fr; gap: 56px; align-items: center; }
        .tentang .foto { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .tentang .foto img { border-radius: 16px; width: 100%; height: 100%; object-fit: cover; aspect-ratio: 4 / 3; }
        .tentang .foto img:first-child { grid-column: span 2; aspect-ratio: 16 / 9; }
        .tentang p:not(.label-bagian) { color: var(--redup); font-size: 17px; }
        .sektor { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 22px; }
        .sektor span { border: 1px solid var(--garis); border-radius: 999px; padding: 6px 14px; font-size: 14px; font-weight: 500; background: var(--latar-2); }
        @media (max-width: 900px) { .tentang .kisi { grid-template-columns: 1fr; gap: 36px; } }

        /* Visi misi */
        .visi { background: var(--latar-2); }
        .visi .kisi { display: grid; grid-template-columns: 1fr 1.3fr; gap: 24px; margin-top: 36px; }
        .kartu-visi { background: var(--merah); color: #fff; border-radius: 18px; padding: 32px; position: relative; overflow: hidden; }
        .kartu-visi h3 { color: #fff; font-size: 22px; margin: 0 0 12px; }
        .kartu-visi p { margin: 0; font-size: 18px; line-height: 1.6; position: relative; z-index: 1; }
        .kartu-visi img { position: absolute; right: -24px; bottom: -28px; width: 170px; opacity: .14; }
        .kartu-misi { background: #fff; border: 1px solid var(--garis); border-radius: 18px; padding: 32px; }
        .kartu-misi h3 { font-size: 22px; margin: 0 0 16px; }
        .kartu-misi ol { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; counter-reset: misi; }
        .kartu-misi li { display: grid; grid-template-columns: 34px 1fr; gap: 12px; color: var(--redup); counter-increment: misi; }
        .kartu-misi li::before { content: counter(misi, decimal-leading-zero); font-family: var(--judul); font-weight: 700; color: var(--merah); font-size: 15px; padding-top: 1px; }
        .nilai { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 24px; }
        .nilai div { background: #fff; border: 1px solid var(--garis); border-radius: 16px; padding: 24px; }
        .nilai svg { color: var(--merah); margin-bottom: 14px; }
        .nilai h3 { font-size: 17px; margin: 0 0 6px; }
        .nilai p { margin: 0; color: var(--redup); font-size: 15px; }
        @media (max-width: 960px) { .visi .kisi { grid-template-columns: 1fr; } .nilai { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .nilai { grid-template-columns: 1fr; } }

        /* Layanan */
        .layanan .kisi { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 40px; }
        .layanan article { border: 1px solid var(--garis); border-radius: 18px; overflow: hidden; background: #fff; display: flex; flex-direction: column; transition: box-shadow .2s, border-color .2s; }
        .layanan article:hover { border-color: #f3c1bb; box-shadow: 0 14px 34px rgba(22, 25, 29, .08); }
        .layanan article img { aspect-ratio: 16 / 10; object-fit: cover; width: 100%; height: auto; }
        .layanan .isi { padding: 22px 24px 26px; }
        .layanan .nomor { font-family: var(--judul); font-weight: 700; color: var(--merah); font-size: 14px; }
        .layanan h3 { font-size: 20px; margin: 4px 0 8px; }
        .layanan .isi p { margin: 0; color: var(--redup); font-size: 15.5px; }
        @media (max-width: 900px) { .layanan .kisi { grid-template-columns: 1fr; } .layanan article { flex-direction: row; } .layanan article img { width: 38%; aspect-ratio: auto; } }
        @media (max-width: 560px) { .layanan article { flex-direction: column; } .layanan article img { width: 100%; aspect-ratio: 16 / 9; } }

        /* Tonggak */
        .tonggak { background: var(--gelap); color: #c4cad2; }
        .tonggak h2.judul-bagian { color: #fff; }
        .tonggak .pengantar { color: #aab2bc; }
        .garis-waktu { list-style: none; margin: 48px 0 0; padding: 0; display: grid; grid-template-columns: repeat(5, 1fr); gap: 36px 20px; position: relative; }
        .garis-waktu li { position: relative; padding-top: 30px; }
        .garis-waktu li::before { content: ''; position: absolute; top: 6px; left: 0; right: -20px; height: 2px; background: #343a42; }
        .garis-waktu li:nth-child(5n)::before, .garis-waktu li:last-child::before { right: 0; }
        .garis-waktu li::after { content: ''; position: absolute; top: 0; left: 0; width: 14px; height: 14px; border-radius: 50%; background: var(--gelap); border: 3px solid var(--merah); }
        .garis-waktu li:last-child::after { border-style: dashed; }
        .garis-waktu time { font-family: var(--judul); font-weight: 600; color: #ff8a7e; font-size: 14px; }
        .garis-waktu h3 { color: #fff; font-size: 17px; margin: 4px 0 6px; }
        .garis-waktu p { margin: 0; font-size: 14.5px; line-height: 1.55; }
        @media (max-width: 960px) {
            .garis-waktu { grid-template-columns: 1fr; gap: 0; padding-left: 4px; }
            .garis-waktu li { padding: 0 0 26px 34px; }
            .garis-waktu li::before { top: 14px; bottom: 0; left: 6px; right: auto; width: 2px; height: auto; }
            .garis-waktu li:nth-child(5n)::before { right: auto; }
            .garis-waktu li:last-child::before { display: none; }
            .garis-waktu li::after { top: 4px; }
        }

        /* Proyek */
        .proyek .kepala-bagian { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; flex-wrap: wrap; }
        .saring { display: flex; flex-wrap: wrap; gap: 8px; }
        .saring button { min-height: 40px; padding: 8px 16px; border-radius: 999px; border: 1px solid var(--garis); background: #fff; font: inherit; font-size: 14.5px; font-weight: 500; color: var(--teks); cursor: pointer; transition: background .15s, border-color .15s, color .15s; }
        .saring button span { color: var(--redup); margin-left: 4px; font-variant-numeric: tabular-nums; }
        .saring button:hover { border-color: var(--merah); }
        .saring button[aria-pressed="true"] { background: var(--merah); border-color: var(--merah); color: #fff; }
        .saring button[aria-pressed="true"] span { color: #ffd9d4; }
        .tabel-bungkus { margin-top: 28px; border: 1px solid var(--garis); border-radius: 16px; overflow: auto; max-height: 620px; }
        table { width: 100%; border-collapse: collapse; font-size: 15px; min-width: 780px; }
        thead th { position: sticky; top: 0; background: var(--gelap); color: #fff; text-align: left; font-family: var(--judul); font-weight: 600; font-size: 13px; letter-spacing: .04em; text-transform: uppercase; padding: 14px 16px; white-space: nowrap; }
        tbody td { padding: 13px 16px; border-top: 1px solid var(--garis); vertical-align: top; }
        tbody tr:nth-child(even) td { background: #fafafb; }
        tbody tr:hover td { background: var(--merah-muda); }
        td.no { color: var(--redup); font-variant-numeric: tabular-nums; width: 48px; }
        td.klien { font-weight: 600; color: var(--gelap); min-width: 210px; }
        td.tgl { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .tag { display: inline-block; font-size: 12.5px; font-weight: 600; padding: 2px 10px; border-radius: 999px; white-space: nowrap; }
        .tag.MNG { background: #fff1e0; color: #8a4b00; }
        .tag.CON { background: var(--merah-muda); color: var(--merah-tua); }
        .tag.FOR { background: #e5f4ea; color: #1d6b3a; }
        .tag.AGR { background: #eef2d9; color: #55620f; }
        .berjalan { color: #1d6b3a; font-weight: 600; }

        /* Izin */
        .izin { background: var(--latar-2); }
        .izin .kisi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 36px; }
        .izin .kisi > div { background: #fff; border: 1px solid var(--garis); border-radius: 14px; padding: 20px; display: flex; gap: 14px; align-items: flex-start; }
        .izin svg { color: var(--merah); flex: none; margin-top: 2px; }
        .izin h3 { font-size: 16px; margin: 0 0 2px; }
        .izin .kisi p { margin: 0; color: var(--redup); font-size: 14px; line-height: 1.5; }
        @media (max-width: 960px) { .izin .kisi { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .izin .kisi { grid-template-columns: 1fr; } }

        /* Kontak */
        .kontak .kisi { display: grid; grid-template-columns: 1fr 1.2fr; gap: 0; border-radius: 20px; overflow: hidden; border: 1px solid var(--garis); }
        .kontak .info { background: var(--merah); color: #fff; padding: 44px 40px; }
        .kontak .info h2 { color: #fff; }
        .kontak .info .label-bagian { color: #ffe1dc; }
        .kontak .info .label-bagian::before { background: #ffe1dc; }
        .kontak .info p:not(.label-bagian) { color: #ffe9e6; margin: 0 0 28px; font-size: 16.5px; }
        .kontak dl { margin: 0 0 28px; display: grid; gap: 18px; }
        .kontak dt { font-size: 12.5px; letter-spacing: .08em; text-transform: uppercase; color: #ffd0c9; margin-bottom: 2px; }
        .kontak dd { margin: 0; font-size: 16.5px; }
        .kontak dd a { color: #fff; }
        .kontak .tombol.putih { background: #fff; color: var(--merah-tua); }
        .kontak .tombol.putih:hover { background: #fff3f1; }
        .kontak iframe { width: 100%; height: 100%; min-height: 420px; border: 0; display: block; background: var(--latar-2); }
        @media (max-width: 860px) { .kontak .kisi { grid-template-columns: 1fr; } .kontak .info { padding: 36px 24px; } .kontak iframe { min-height: 320px; } }
        @media (max-width: 640px) { section { padding: 64px 0; } }
    </style>
@endpush

@section('isi')
    {{-- Pembuka --}}
    <section class="pembuka" aria-labelledby="judul-pembuka">
        <img src="{{ asset('profil/armada.webp') }}" alt="Fleet of MNP dump trucks lined up at a land development site" width="1770" height="996" fetchpriority="high">
        <div class="bungkus">
            <p class="label-bagian">PT Multi Niaga Putra · Since 2019</p>
            <h1 id="judul-pembuka">Heavy equipment &amp; earthwork contractor you can <em>rely on</em>.</h1>
            <p class="isi">Equipment rental, land clearing and sectoral contracting for mining, forestry, agro and construction projects —
                from Jakarta to Kalimantan, Sumatra and Sulawesi.</p>
            <div class="aksi">
                <a href="#services" class="tombol utama">Our services</a>
                <a href="#contact" class="tombol garis">Contact us</a>
            </div>
            <div class="angka">
                <div><b>2019</b><span>Established in Jakarta</span></div>
                <div><b>{{ count($proyek) }}+</b><span>Projects delivered &amp; running</span></div>
                <div><b>{{ $jumlahKlien }}</b><span>Clients served</span></div>
                <div><b>4</b><span>Sectors: mining, forestry, agro, construction</span></div>
            </div>
        </div>
    </section>

    {{-- Tentang --}}
    <section class="tentang" id="about" aria-labelledby="judul-tentang">
        <div class="bungkus kisi">
            <div>
                <p class="label-bagian">About our company</p>
                <h2 class="judul-bagian" id="judul-tentang">From an equipment rental agency to a multi-sector contractor</h2>
                <p>Established in 2019, MNP started as a heavy equipment rental agency in Java. With our expertise in heavy equipment management,
                    fuel efficiency and equipment productivity, we expanded our business into coal mining, forestry, construction and land cut &amp; fill projects.</p>
                <p>Customer satisfaction remains our top priority as we continually create new value and deliver faster innovation.</p>
                <div class="sektor" aria-label="Sectors">
                    <span>Coal mining</span><span>Forestry</span><span>Agro</span><span>Construction</span><span>Land cut &amp; fill</span>
                </div>
            </div>
            <div class="foto">
                <img src="{{ asset('profil/galian-kanal.webp') }}" alt="MNP excavator shaping a canal embankment at Pantai Mutiara, North Jakarta" width="1400" height="1072" loading="lazy">
                <img src="{{ asset('profil/excavator-jalan.webp') }}" alt="Excavator working on a hauling road" width="644" height="362" loading="lazy">
                <img src="{{ asset('profil/truk-mnp.webp') }}" alt="MNP dump truck being loaded by an excavator" width="533" height="401" loading="lazy">
            </div>
        </div>
    </section>

    {{-- Visi, misi, nilai --}}
    <section class="visi" aria-labelledby="judul-visi">
        <div class="bungkus">
            <p class="label-bagian">Vision, mission &amp; values</p>
            <h2 class="judul-bagian" id="judul-visi">What drives us</h2>
            <div class="kisi">
                <div class="kartu-visi">
                    <h3>Vision</h3>
                    <p>To excel as a heavy equipment contractor in the mining, forestry and construction sectors and land cut &amp; fill projects, providing exceptional priority service.</p>
                    <img src="{{ asset('profil/logo-putih.svg') }}" alt="">
                </div>
                <div class="kartu-misi">
                    <h3>Mission</h3>
                    <ol>
                        <li>Align and harmonize customer expectations by empowering our outstanding equipment.</li>
                        <li>Develop our people to uphold our corporate values and enhance their social standing through valuable achievements.</li>
                        <li>Maintain good corporate governance to create added value and compete in the construction, forestry and mining sectors.</li>
                        <li>Dedicate our corporate values to contribute to the nation's prosperity.</li>
                    </ol>
                </div>
            </div>
            <div class="nilai">
                <div>
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/></svg>
                    <h3>Commitment</h3>
                    <p>We fully commit to our customers in every project we undertake, aiming to be their trusted partner.</p>
                </div>
                <div>
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg>
                    <h3>Customer Focus</h3>
                    <p>Keeping our customers satisfied is paramount — we prioritize understanding and fulfilling their needs.</p>
                </div>
                <div>
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                    <h3>Integrity</h3>
                    <p>Upholding an excellent reputation and maintaining customer trust is of utmost importance to us.</p>
                </div>
                <div>
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <h3>Team Work</h3>
                    <p>Respecting colleagues and valuing diverse opinions lets us find the best solutions together.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Layanan --}}
    <section class="layanan" id="services" aria-labelledby="judul-layanan">
        <div class="bungkus">
            <p class="label-bagian">Our services</p>
            <h2 class="judul-bagian" id="judul-layanan">What we do in the field</h2>
            <p class="pengantar">Our own fleet of excavators, bulldozers and dump trucks, run by an experienced field team.</p>
            <div class="kisi">
                <article>
                    <img src="{{ asset('profil/bulldozer.webp') }}" alt="Bulldozer clearing land" width="499" height="665" loading="lazy" style="object-position: center 70%">
                    <div class="isi">
                        <span class="nomor">01</span>
                        <h3>Land Clearing &amp; Harvesting</h3>
                        <p>Removal of forest or tree stands so the land can be converted to non-forest use — using excavators (bucket and log grapple), chainsaw operators and logging trucks.</p>
                    </div>
                </article>
                <article>
                    <img src="{{ asset('profil/excavator-komatsu.webp') }}" alt="Komatsu excavator loading material" width="590" height="332" loading="lazy">
                    <div class="isi">
                        <span class="nomor">02</span>
                        <h3>Heavy Equipment Rental</h3>
                        <p>Quality heavy equipment and operator expertise, managed for fuel efficiency and productivity to reach optimum performance on your site.</p>
                    </div>
                </article>
                <article>
                    <img src="{{ asset('profil/dump-truck.webp') }}" alt="Dump truck unloading fill material next to an excavator" width="1400" height="948" loading="lazy">
                    <div class="isi">
                        <span class="nomor">03</span>
                        <h3>Sectoral Contractor</h3>
                        <p>Fulfilling our customers' needs such as cut &amp; fill, subgrading, surfacing, hauling roads and water piping.</p>
                    </div>
                </article>
            </div>
        </div>
    </section>

    {{-- Tonggak --}}
    <section class="tonggak" id="milestones" aria-labelledby="judul-tonggak">
        <div class="bungkus">
            <p class="label-bagian">Milestones</p>
            <h2 class="judul-bagian" id="judul-tonggak">Our journey since 2019</h2>
            <p class="pengantar">From two rental units in Jakarta to multi-sector projects across Indonesia.</p>
            <ol class="garis-waktu">
                @foreach ($tonggak as [$waktu, $judul, $isi])
                    <li><time>{{ $waktu }}</time><h3>{{ $judul }}</h3><p>{{ $isi }}</p></li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Proyek --}}
    <section class="proyek" id="projects" aria-labelledby="judul-proyek">
        <div class="bungkus">
            <div class="kepala-bagian">
                <div>
                    <p class="label-bagian">Our customers</p>
                    <h2 class="judul-bagian" id="judul-proyek">Project list</h2>
                    <p class="pengantar">{{ count($proyek) }} projects with {{ $jumlahKlien }} clients across mining, construction, forestry and agro.</p>
                </div>
                <div class="saring" role="group" aria-label="Filter by sector">
                    <button type="button" aria-pressed="true" data-sektor="">All<span>{{ count($proyek) }}</span></button>
                    @foreach ($sektor as $kode => $nama)
                        <button type="button" aria-pressed="false" data-sektor="{{ $kode }}">{{ $nama }}<span>{{ $jumlahSektor[$kode] ?? 0 }}</span></button>
                    @endforeach
                </div>
            </div>
            <div class="tabel-bungkus" tabindex="0" aria-label="Project list table">
                <table>
                    <thead><tr><th>No</th><th>Customer</th><th>Sector</th><th>Period</th><th>Project</th><th>Location</th><th>Type</th></tr></thead>
                    <tbody>
                        @foreach ($proyek as $i => [$klien, $kode, $tgl, $isi, $lokasi, $jenis])
                            <tr data-sektor="{{ $kode }}">
                                <td class="no">{{ $i + 1 }}</td>
                                <td class="klien">{{ $klien }}</td>
                                <td><span class="tag {{ $kode }}">{{ $sektor[$kode] }}</span></td>
                                <td class="tgl">{!! str_ends_with($tgl, 'Now') ? e(substr($tgl, 0, -3)).'<span class="berjalan">Now</span>' : e($tgl) !!}</td>
                                <td>{{ $isi }}</td>
                                <td>{{ $lokasi }}</td>
                                <td>{{ $jenis }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Izin --}}
    <section class="izin" id="permits" aria-labelledby="judul-izin">
        <div class="bungkus">
            <p class="label-bagian">Licenses &amp; permits</p>
            <h2 class="judul-bagian" id="judul-izin">Fully licensed to operate</h2>
            <p class="pengantar">Copies of our legal documents are available on request for tender and vendor registration.</p>
            <div class="kisi">
                @foreach ($izin as [$nama, $ket])
                    <div>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/></svg>
                        <div><h3>{{ $nama }}</h3><p>{{ $ket }}</p></div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Kontak --}}
    <section class="kontak" id="contact" aria-labelledby="judul-kontak">
        <div class="bungkus">
            <div class="kisi">
                <div class="info">
                    <p class="label-bagian">Contact</p>
                    <h2 class="judul-bagian" id="judul-kontak">Let's discuss your project</h2>
                    <p>Need equipment on site or a contractor for land clearing, hauling roads or cut &amp; fill? Send us your project details.</p>
                    <dl>
                        <div><dt>Office</dt><dd>Jalan Raya Gading Batavia Blok LC-11 No. 8, Kelurahan Kelapa Gading Barat, Kecamatan Kelapa Gading, Jakarta Utara</dd></div>
                        <div><dt>Sales</dt><dd><a href="mailto:adm_sales@multiniagaputra.com">adm_sales@multiniagaputra.com</a></dd></div>
                        <div><dt>General</dt><dd><a href="mailto:ptmultiniagaputra@gmail.com">ptmultiniagaputra@gmail.com</a></dd></div>
                    </dl>
                    <a class="tombol putih" href="mailto:adm_sales@multiniagaputra.com?subject=Project%20inquiry">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                        Email our sales team
                    </a>
                </div>
                <iframe title="Office location on Google Maps" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                        src="https://www.google.com/maps?q={{ urlencode('Jl. Raya Gading Batavia Blok LC-11 No. 8, Kelapa Gading Barat, Jakarta Utara') }}&output=embed"></iframe>
            </div>
        </div>
    </section>
@endsection

@push('skrip')
    <script>
        (() => {
            const tombol = document.querySelectorAll('.saring button'), baris = document.querySelectorAll('.proyek tbody tr');
            tombol.forEach(t => t.addEventListener('click', () => {
                const s = t.dataset.sektor;
                tombol.forEach(x => x.setAttribute('aria-pressed', x === t));
                baris.forEach(r => { r.hidden = s !== '' && r.dataset.sektor !== s; });
            }));
        })();
    </script>
@endpush
