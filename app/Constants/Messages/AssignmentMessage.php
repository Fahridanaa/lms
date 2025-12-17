<?php

namespace App\Constants\Messages;

class AssignmentMessage
{
    public const SUBMITTED = 'Tugas berhasil dikumpulkan';
    public const GRADED = 'Pengumpulan tugas berhasil dinilai';
    public const NOT_FOUND = 'Tugas tidak ditemukan';
    public const ALREADY_SUBMITTED = 'Anda sudah mengumpulkan tugas ini';
    public const DEADLINE_PASSED = 'Batas waktu pengumpulan telah lewat';
}
