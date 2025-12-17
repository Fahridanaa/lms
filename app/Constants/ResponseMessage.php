<?php

namespace App\Constants;

class ResponseMessage
{
    public const SUCCESS = 'Berhasil';
    public const CREATED = 'Data berhasil dibuat';
    public const UPDATED = 'Data berhasil diperbarui';
    public const DELETED = 'Data berhasil dihapus';
    public const NOT_FOUND = 'Data tidak ditemukan';
    public const VALIDATION_ERROR = 'Validasi gagal';
    public const SERVER_ERROR = 'Terjadi kesalahan pada server';
    public const UNAUTHORIZED = 'Akses tidak terotorisasi';
    public const FORBIDDEN = 'Akses ditolak';
    public const NO_CONTENT = 'Tidak ada konten';
}
