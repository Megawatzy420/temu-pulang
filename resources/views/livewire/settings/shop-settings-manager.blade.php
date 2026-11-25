<div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-5xl mx-auto">

    <x-notification />

    <!-- Top Settings Header & Save Button -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-4 border-b border-coffee-200/80">
        <div>
            <h2 class="text-xl font-bold text-coffee-950 tracking-tight">Pengaturan Toko & POS</h2>
            <p class="text-xs text-coffee-600">Kelola identitas kedai, jam operasional, tarif pajak default, dan perangkat keras</p>
        </div>

        <x-button
            type="button"
            variant="primary"
            size="md"
            wire:click="saveSettings"
            class="bg-coffee-900 hover:bg-caramel-600 text-white shadow-md cursor-pointer px-6"
        >
            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            Simpan Perubahan
        </x-button>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-coffee-200/60 no-scrollbar">
        <button
            type="button"
            wire:click="$set('activeTab', 'profile')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'profile' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-700 hover:bg-cream-100 hover:text-coffee-950' }}"
        >
            Profil Usaha
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'hours')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'hours' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-700 hover:bg-cream-100 hover:text-coffee-950' }}"
        >
            Jam Operasional & Hari Bisnis
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'finance')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'finance' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-700 hover:bg-cream-100 hover:text-coffee-950' }}"
        >
            Pajak & Finansial (Default 0%)
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'printer')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'printer' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-700 hover:bg-cream-100 hover:text-coffee-950' }}"
        >
            Printer Thermal USB
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'receipt')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'receipt' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-700 hover:bg-cream-100 hover:text-coffee-950' }}"
        >
            Format Struk
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'qris')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'qris' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-700 hover:bg-cream-100 hover:text-coffee-950' }}"
        >
            {{ __('messages.settings.tab_qris') }}
        </button>
    </div>

    <!-- TAB 1: PROFIL USAHA -->
    @if ($activeTab === 'profile')
        <div class="bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-5">
            <h3 class="text-base font-bold text-coffee-950 border-b border-coffee-100 pb-3">Identitas & Kontak Usaha</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Nama Usaha / Kedai <span class="text-rose-500">*</span></label>
                    <input
                        type="text"
                        wire:model="name"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 text-sm font-semibold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                    />
                    @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Nomor Telepon</label>
                    <input
                        type="text"
                        wire:model="phone"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 text-sm font-semibold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                    />
                    @error('phone') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Alamat Email Toko</label>
                    <input
                        type="email"
                        wire:model="email"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 text-sm font-semibold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                    />
                    @error('email') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Mata Uang & Timezone</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input
                            type="text"
                            value="IDR (Rp)"
                            disabled
                            class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 bg-cream-50 text-xs font-bold text-coffee-600 cursor-not-allowed"
                        />
                        <input
                            type="text"
                            value="Asia/Jakarta (WIB)"
                            disabled
                            class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 bg-cream-50 text-xs font-bold text-coffee-600 cursor-not-allowed"
                        />
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Alamat Lengkap Outlet</label>
                    <textarea
                        wire:model="address"
                        rows="2"
                        class="w-full p-3.5 rounded-xl border border-coffee-200 text-xs font-medium text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                    ></textarea>
                    @error('address') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
    @endif

    <!-- TAB 2: JAM OPERASIONAL & HARI BISNIS -->
    @if ($activeTab === 'hours')
        <div class="bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-6">
            <div class="pb-3 border-b border-coffee-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h3 class="text-base font-bold text-coffee-950">Jam Operasional Mingguan</h3>
                    <p class="text-xs text-coffee-500">Mendukung jam operasional yang melintasi tengah malam (misal buka 18:00 tutup 02:00)</p>
                </div>
            </div>

            <!-- Business Day Start Time Setting -->
            <div class="p-4 rounded-2xl bg-cream-50/80 border border-coffee-200/80 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <h4 class="text-xs font-bold text-coffee-950 uppercase tracking-wider">Waktu Mulai Hari Bisnis (Business Day Cutoff)</h4>
                    <p class="text-xs text-coffee-600 mt-0.5">Transaksi sebelum jam ini dihitung masuk ke hari operasional sebelumnya (Contoh: 04:00 WIB)</p>
                </div>
                <div class="w-32">
                    <input
                        type="time"
                        wire:model="businessDayStartTime"
                        class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-sm font-bold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                    />
                    @error('businessDayStartTime') <p class="text-[10px] text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <!-- Days Grid -->
            <div class="space-y-2.5">
                @php
                    $daysLabel = [
                        'monday' => 'Senin',
                        'tuesday' => 'Selasa',
                        'wednesday' => 'Rabu',
                        'thursday' => 'Kamis',
                        'friday' => 'Jumat',
                        'saturday' => 'Sabtu',
                        'sunday' => 'Minggu',
                    ];
                @endphp

                @foreach ($daysLabel as $key => $label)
                    <div class="flex items-center justify-between p-3 rounded-2xl border border-coffee-200/60 bg-cream-50/30 gap-3">
                        <span class="w-24 text-xs font-bold text-coffee-900">{{ $label }}</span>

                        <div class="flex items-center gap-2 flex-1 justify-end">
                            <label class="flex items-center gap-1.5 text-xs text-coffee-600 cursor-pointer mr-3">
                                <input
                                    type="checkbox"
                                    wire:model="operatingHours.{{ $key }}.is_closed"
                                    class="rounded text-coffee-900 focus:ring-coffee-400"
                                />
                                <span>Tutup</span>
                            </label>

                            @if (empty($operatingHours[$key]['is_closed']))
                                <input
                                    type="time"
                                    wire:model="operatingHours.{{ $key }}.open"
                                    class="px-2.5 py-1.5 rounded-lg border border-coffee-200 text-xs font-medium text-coffee-950 bg-white"
                                />
                                <span class="text-xs text-coffee-400">s/d</span>
                                <input
                                    type="time"
                                    wire:model="operatingHours.{{ $key }}.close"
                                    class="px-2.5 py-1.5 rounded-lg border border-coffee-200 text-xs font-medium text-coffee-950 bg-white"
                                />
                            @else
                                <span class="text-xs font-bold text-rose-600 italic">Kedai Tutup</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- TAB 3: PAJAK & FINANSIAL -->
    @if ($activeTab === 'finance')
        <div class="bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-6">
            <div class="pb-3 border-b border-coffee-100">
                <h3 class="text-base font-bold text-coffee-950">Default Finansial & Tarif Pajak</h3>
                <p class="text-xs text-coffee-500">Nilai default resmi adalah 0%. Perubahan tarif hanya berlaku untuk pesanan baru.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Tax Setting -->
                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/70 space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-bold text-coffee-900 uppercase tracking-wider block">
                            Tarif Pajak Restoran (PB1) %
                        </label>
                        <x-badge variant="neutral" size="xs">Default: 0%</x-badge>
                    </div>
                    <div class="relative">
                        <input
                            type="number"
                            step="0.1"
                            min="0"
                            max="100"
                            wire:model="taxPercentage"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 text-sm font-bold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="0"
                        />
                        <span class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-coffee-500 pointer-events-none">
                            %
                        </span>
                    </div>
                    @error('taxPercentage') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    <p class="text-[11px] text-coffee-500">
                        Disimpan presisi menggunakan basis point integer (misal: 10% = 1000 bp).
                    </p>

                    <label class="flex items-center gap-2 pt-2 text-xs text-coffee-800 font-medium cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model="isTaxInclusive"
                            class="rounded text-coffee-900 focus:ring-coffee-400"
                        />
                        <span>Harga menu sudah termasuk pajak (Tax Inclusive)</span>
                    </label>
                </div>

                <!-- Service Charge Setting -->
                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/70 space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-bold text-coffee-900 uppercase tracking-wider block">
                            Biaya Layanan (Service Charge) %
                        </label>
                        <x-badge variant="neutral" size="xs">Default: 0%</x-badge>
                    </div>
                    <div class="relative">
                        <input
                            type="number"
                            step="0.1"
                            min="0"
                            max="100"
                            wire:model="serviceChargePercentage"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 text-sm font-bold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="0"
                        />
                        <span class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-coffee-500 pointer-events-none">
                            %
                        </span>
                    </div>
                    @error('serviceChargePercentage') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    <p class="text-[11px] text-coffee-500">
                        Tambahan biaya layanan restoran/kedai jika diterapkan.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <!-- TAB 4: PRINTER THERMAL USB -->
    @if ($activeTab === 'printer')
        <div class="bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-6">
            <div class="pb-3 border-b border-coffee-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-coffee-950">Konfigurasi Printer Kasir (Koneksi USB)</h3>
                    <p class="text-xs text-coffee-500">Printer thermal terhubung langsung ke PC Windows server kasir melalui kabel USB</p>
                </div>
                <x-badge variant="success" size="sm">USB Terpilih</x-badge>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Tipe Koneksi Printer</label>
                    <input
                        type="text"
                        value="USB (Direct Windows Printer)"
                        disabled
                        class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 bg-cream-50 text-xs font-bold text-coffee-700 cursor-not-allowed"
                    />
                    <p class="text-[11px] text-coffee-400 mt-1">Tidak memerlukan alamat IP atau port jaringan TCP.</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Lebar Kertas Struk <span class="text-rose-500">*</span></label>
                    <select
                        wire:model="printerPaperWidth"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 text-xs font-bold text-coffee-900 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                    >
                        <option value="80mm">80mm (Standar thermal receipt)</option>
                        <option value="58mm">58mm (Kertas thermal mini)</option>
                    </select>
                    @error('printerPaperWidth') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Nama Perangkat Printer Windows</label>
                    <input
                        type="text"
                        wire:model="printerName"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 text-sm font-semibold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                        placeholder="Contoh: POS-80, Thermal Printer USB"
                    />
                    <p class="text-[11px] text-coffee-400 mt-1">Nama printer persis seperti yang terdaftar di Windows Devices and Printers.</p>
                    @error('printerName') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Status Printer Kasir</label>
                    <label class="flex items-center gap-2 mt-2 text-xs font-semibold text-coffee-900 cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model="printerEnabled"
                            class="rounded text-coffee-900 focus:ring-coffee-400"
                        />
                        <span>Aktifkan pencetakan otomatis ke printer kasir</span>
                    </label>
                </div>

                <!-- Kitchen Printer Profile (Optional / Future use) -->
                <div class="md:col-span-2 pt-4 border-t border-coffee-100">
                    <h4 class="text-xs font-bold text-coffee-900 uppercase tracking-wider mb-2">Profil Printer Dapur (Kitchen Printer - Persiapan Mendatang)</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="flex items-center gap-2 text-xs text-coffee-800 font-medium cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model="kitchenPrinterEnabled"
                                class="rounded text-coffee-900 focus:ring-coffee-400"
                            />
                            <span>Aktifkan printer terpisah untuk tiket dapur</span>
                        </label>

                        @if ($kitchenPrinterEnabled)
                            <input
                                type="text"
                                wire:model="kitchenPrinterName"
                                class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-950"
                                placeholder="Nama printer dapur di Windows (misal: Kitchen-58)"
                            />
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- TAB 5: FORMAT STRUK -->
    @if ($activeTab === 'receipt')
        <div class="bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-5">
            <h3 class="text-base font-bold text-coffee-950 border-b border-coffee-100 pb-3">Kustomisasi Header & Footer Struk</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Header Struk (Atas)</label>
                    <textarea
                        wire:model="receiptHeader"
                        rows="4"
                        class="w-full p-3 rounded-xl border border-coffee-200 text-xs font-mono text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                        placeholder="TEMU PULANG&#10;Coffee & Eatery&#10;Jl. Boulevard Raya Blok A No. 12"
                    ></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-coffee-800 mb-1">Footer Struk (Bawah)</label>
                    <textarea
                        wire:model="receiptFooter"
                        rows="4"
                        class="w-full p-3 rounded-xl border border-coffee-200 text-xs font-mono text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                        placeholder="Terima kasih atas kunjungan Anda!&#10;WiFi: TemuPulang | Pass: kopienak"
                    ></textarea>
                </div>

                <div class="md:col-span-2 flex items-center gap-6 pt-2 flex-wrap text-xs text-coffee-800 font-semibold">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="receiptShowLogo" class="rounded text-coffee-900" />
                        <span>Tampilkan Logo di Struk</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="receiptShowTax" class="rounded text-coffee-900" />
                        <span>Tampilkan Rincian Pajak di Struk</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="receiptShowCustomerInfo" class="rounded text-coffee-900" />
                        <span>Tampilkan Info Pelanggan di Struk</span>
                    </label>
                </div>
            </div>
        </div>
    @endif

    <!-- TAB 6: QRIS -->
    @if ($activeTab === 'qris')
        <div class="bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-4">
            <div class="pb-3 border-b border-coffee-100">
                <h3 class="text-base font-bold text-coffee-950">{{ __('messages.settings.qris_title') }}</h3>
                <p class="text-xs text-coffee-500 mt-0.5">{{ __('messages.settings.qris_subtitle') }}</p>
            </div>

            @if ($qrisImagePath !== '')
                <div class="flex flex-col items-center gap-3 p-4 rounded-2xl bg-cream-50 border border-coffee-200/80">
                    <img
                        src="{{ Storage::url($qrisImagePath) }}"
                        alt="QRIS"
                        class="max-h-56 w-auto object-contain rounded-xl border border-coffee-200 shadow-sm"
                    />
                    <button
                        type="button"
                        wire:click="removeQrisImage"
                        wire:confirm="{{ __('messages.settings.qris_remove_confirm') }}"
                        class="text-xs font-bold text-rose-600 hover:text-rose-800 underline underline-offset-2 cursor-pointer"
                    >
                        {{ __('messages.settings.qris_remove_btn') }}
                    </button>
                </div>
            @else
                <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-xs text-amber-900 font-semibold">
                    {{ __('messages.settings.qris_not_set') }}
                </div>
            @endif

            <div>
                <label class="block text-xs font-bold text-coffee-800 mb-2">
                    {{ $qrisImagePath !== '' ? __('messages.settings.qris_replace_label') : __('messages.settings.qris_upload_label') }}
                    <span class="text-coffee-400 font-normal">({{ __('messages.settings.qris_hint') }})</span>
                </label>
                <input
                    type="file"
                    wire:model="qrisImageUpload"
                    accept=".png,.webp,image/png,image/webp"
                    class="block w-full text-xs text-coffee-900 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-coffee-900 file:text-cream-50 hover:file:bg-coffee-800 file:cursor-pointer cursor-pointer"
                />
                @error('qrisImageUpload')
                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <x-button
                type="button"
                variant="primary"
                size="sm"
                wire:click="uploadQrisImage"
                wire:loading.attr="disabled"
                class="bg-coffee-900 hover:bg-caramel-600 text-white cursor-pointer"
            >
                {{ __('messages.settings.qris_upload_btn') }}
            </x-button>
        </div>
    @endif

</div>

