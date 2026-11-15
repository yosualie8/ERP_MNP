// Membakukan ucapan Indonesia sehari-hari (gaul Jakarta, campuran Sunda/Jawa) sebelum diterjemahkan ke Mandarin.
// Google Translate (sl=id) salah membaca kata daerah — mis. "urang mau makan bakso" jadi 我不想吃肉丸 ("tidak mau").
// Kamus 2 tingkat: kata yang aman selalu diganti; kata yang bermakna lain dalam bahasa Indonesia
// (tuang = menuang, mangga = buah) hanya diganti bila kalimat jelas berbahasa daerah.
(function (akar) {
  'use strict';

  // Frasa diganti lebih dulu (urutan: yang panjang dulu).
  const FRASA = [
    ['kumaha damang', 'apa kabar'], ['kumaha kabar', 'apa kabar'], ['hatur nuhun', 'terima kasih'], ['matur nuwun', 'terima kasih'],
    ['matur suwun', 'terima kasih'], ['teu acan', 'belum'], ['can acan', 'belum'], ['teu kenging', 'tidak boleh'], ['teu tiasa', 'tidak bisa'],
    ['teu bisa', 'tidak bisa'], ['teu aya', 'tidak ada'], ['teu nanaon', 'tidak apa-apa'], ['nteu nanaon', 'tidak apa-apa'],
    ['ora opo opo', 'tidak apa-apa'], ['ora popo', 'tidak apa-apa'], ['gak papa', 'tidak apa-apa'], ['ga papa', 'tidak apa-apa'],
    ['nggak papa', 'tidak apa-apa'], ['gpp', 'tidak apa-apa'], ['gak usah', 'tidak usah'], ['ga usah', 'tidak usah'],
    ['nggak usah', 'tidak usah'], ['berapaan', 'berapa harganya'], ['sabaraha hargina', 'berapa harganya'], ['pinten reginipun', 'berapa harganya'],
    ['piro regane', 'berapa harganya'], ['sing endi', 'yang mana'], ['nu mana', 'yang mana'], ['nuju naon', 'sedang apa'],
  ];

  // Kata yang selalu aman diganti (tidak bermakna lain dalam bahasa Indonesia baku).
  const KATA = {
    // gaul Jakarta / tulisan cepat
    gue: 'saya', gua: 'saya', gw: 'saya', guwe: 'saya', aing: 'saya', urang: 'saya', abdi: 'saya', kula: 'saya',
    lu: 'kamu', lo: 'kamu', elu: 'kamu', elo: 'kamu', loe: 'kamu', maneh: 'kamu', anjeun: 'kamu', sampeyan: 'kamu', kowe: 'kamu', koe: 'kamu', panjenengan: 'Anda',
    gak: 'tidak', ga: 'tidak', gk: 'tidak', nggak: 'tidak', ngga: 'tidak', enggak: 'tidak', engga: 'tidak', kagak: 'tidak', kaga: 'tidak',
    teu: 'tidak', henteu: 'tidak', ora: 'tidak', mboten: 'tidak', embung: 'tidak mau', moal: 'tidak akan',
    udah: 'sudah', udh: 'sudah', dah: 'sudah', geus: 'sudah', atos: 'sudah', wis: 'sudah', uwis: 'sudah', sampun: 'sudah',
    belom: 'belum', blm: 'belum', durung: 'belum', dereng: 'belum',
    aja: 'saja', aj: 'saja', doang: 'saja', wae: 'saja', bae: 'saja', mawon: 'saja',
    pengen: 'ingin', pingin: 'ingin', pengin: 'ingin', pgn: 'ingin', hoyong: 'ingin', hayang: 'ingin', kepengen: 'ingin',
    bade: 'mau', rek: 'mau', arek: 'mau', arep: 'mau', badhe: 'mau',
    pake: 'pakai', pakek: 'pakai', sambel: 'sambal', pedes: 'pedas', gimana: 'bagaimana', gmn: 'bagaimana',
    kumaha: 'bagaimana', piye: 'bagaimana', pripun: 'bagaimana', naha: 'kenapa', ngapa: 'kenapa',
    naon: 'apa', opo: 'apa', nopo: 'apa', apaan: 'apa', sabaraha: 'berapa', piro: 'berapa', pinten: 'berapa',
    dimana: 'di mana', endi: 'mana', kamana: 'ke mana', kemana: 'ke mana',
    nih: 'ini', ieu: 'ini', iki: 'ini', niki: 'ini', tuh: 'itu', eta: 'itu', kuwi: 'itu', iku: 'itu', niku: 'itu',
    gini: 'begini', gitu: 'begitu', kayak: 'seperti', kek: 'seperti', kyk: 'seperti', kalo: 'kalau', klo: 'kalau', kl: 'kalau',
    karna: 'karena', krn: 'karena', emang: 'memang', emg: 'memang', banget: 'sekali', bgt: 'sekali', pisan: 'sekali', tenan: 'sekali',
    bikin: 'membuat', cuma: 'hanya', cuman: 'hanya', ngan: 'hanya', mung: 'hanya', liat: 'lihat', tau: 'tahu', tw: 'tahu',
    makasih: 'terima kasih', makasi: 'terima kasih', mksh: 'terima kasih', trims: 'terima kasih', nuhun: 'terima kasih',
    abis: 'habis', ntar: 'nanti', entar: 'nanti', bentar: 'sebentar', heula: 'dulu', dhisik: 'dulu', disik: 'dulu',
    gede: 'besar', gedhe: 'besar', ageung: 'besar', dikit: 'sedikit', saeutik: 'sedikit', sithik: 'sedikit', akeh: 'banyak', seueur: 'banyak', loba: 'banyak',
    yg: 'yang', nu: 'yang', sing: 'yang', dgn: 'dengan', sareng: 'dengan', jeung: 'dan', karo: 'dengan', kaliyan: 'dengan',
    tp: 'tapi', tapih: 'tapi', sm: 'sama', utk: 'untuk', jg: 'juga', oge: 'juga', uga: 'juga',
    dahar: 'makan', emam: 'makan', mangan: 'makan', nedha: 'makan', maem: 'makan', nginum: 'minum', ngombe: 'minum', ngunjuk: 'minum',
    meuli: 'beli', meser: 'beli', tuku: 'beli', tumbas: 'beli', mayar: 'bayar', sangu: 'nasi', sego: 'nasi', sekul: 'nasi',
    calik: 'duduk', diuk: 'duduk', lungguh: 'duduk', indit: 'pergi', lunga: 'pergi', mulih: 'pulang', wangsul: 'pulang',
    mangkat: 'berangkat', dongkap: 'datang', teka: 'datang', sumping: 'datang', bobo: 'tidur', sare: 'tidur', turu: 'tidur',
    raos: 'enak', ngeunah: 'enak', uenak: 'enak', larang: 'mahal', mirah: 'murah',
    ayeuna: 'sekarang', saiki: 'sekarang', sakniki: 'sekarang', kamari: 'kemarin', wingi: 'kemarin', isukan: 'besok', sesuk: 'besok', enjing: 'besok',
    tos: 'sudah', parantos: 'sudah', acan: 'belum', aya: 'ada', wonten: 'ada', kudu: 'harus', kedah: 'harus',
    tiasa: 'bisa', iso: 'bisa', saged: 'bisa', hiji: 'satu', tilu: 'tiga', siji: 'satu', loro: 'dua', telu: 'tiga',
  };
  // Partikel yang tidak menambah makna — dibuang supaya mesin terjemah tidak bingung.
  // "teh" sengaja tidak dibuang: dalam bahasa Indonesia = minuman teh.
  const BUANG = new Set(['dong', 'sih', 'deh', 'kok', 'euy', 'atuh', 'mah', 'jeh', 'lho', 'loh', 'tho', 'toh', 'cuy', 'mbok']);

  // Kata daerah yang dalam bahasa Indonesia bermakna lain — hanya diganti bila kalimat jelas berbahasa daerah.
  const KATA_DAERAH = { tuang: 'makan', mangga: 'silakan', monggo: 'silakan', cai: 'air', banyu: 'air', sae: 'bagus', apik: 'bagus', kantun: 'tinggal',
    lada: 'pedas', balik: 'pulang', ana: 'ada', ari: 'kalau' };
  // Penanda kalimat daerah (kata yang tidak dipakai dalam bahasa Indonesia).
  const PENANDA = new Set(['urang', 'abdi', 'hoyong', 'hayang', 'teu', 'henteu', 'kumaha', 'naon', 'euy', 'atuh', 'heula', 'pisan', 'sareng', 'jeung',
    'dahar', 'calik', 'diuk', 'nuhun', 'hatur', 'geus', 'acan', 'bade', 'rek', 'sabaraha', 'ieu', 'eta', 'maneh', 'anjeun', 'ayeuna', 'aya', 'mah',
    'ora', 'opo', 'piye', 'arep', 'wis', 'durung', 'mangan', 'ngombe', 'sampun', 'dereng', 'badhe', 'matur', 'nuwun', 'suwun', 'pinten', 'iki', 'kuwi', 'sing',
    'sego', 'tuku', 'kowe', 'sampeyan', 'monggo', 'mboten', 'wonten', 'saiki', 'pripun', 'nedha', 'ngunjuk', 'tumbas', 'meser', 'meuli', 'tos', 'parantos']);

  function bakukan(teks) {
    if (!teks) return teks;
    let s = ' ' + teks.replace(/\s+/g, ' ').trim() + ' ';
    for (const [a, b] of FRASA) s = s.replace(new RegExp('(^|[^\\p{L}])' + a.replace(/ /g, '\\s+') + '(?=[^\\p{L}]|$)', 'giu'), '$1' + b);
    const kata = s.toLowerCase().match(/\p{L}+/gu) || [];
    const daerah = kata.filter(k => PENANDA.has(k)).length > 0;
    s = s.replace(/\p{L}+/gu, k => {
      const kecil = k.toLowerCase();
      if (BUANG.has(kecil)) return '';
      if (daerah && KATA_DAERAH[kecil]) return KATA_DAERAH[kecil];
      if (Object.prototype.hasOwnProperty.call(KATA, kecil)) return KATA[kecil];
      return k;
    });
    return s.replace(/\s+([,.!?])/g, '$1').replace(/\s+/g, ' ').replace(/^[\s,]+|[\s,]+$/g, '').replace(/\bterima kasih terima kasih\b/gi, 'terima kasih');
  }


  akar.bakukan = bakukan;
})(globalThis);
