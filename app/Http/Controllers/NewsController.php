<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsTag;
use Illuminate\Support\Str;
use App\Services\CheckAdminService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

// TODO: LANJUTKAN CHANGE STATUS, UPDATE DAN DELETE !!!!!!!!!!!!!!!!!!!!!

class NewsController extends Controller
{
    protected $checkAdminService;

    // Inject RoleService ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }

    public function getAllNews(Request $request)
    {
        // Cek apakah user adalah admin
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Ambil parameter query dari request
            $titleNews = $request->query('title');
            $status = $request->query('status');

            // Query dasar untuk mengambil berita dengan relasi creator dan newsTags
            $query = News::with(['creator:id,name,email', 'newsTags']);

            // Tambahkan filter berdasarkan nama creator jika ada
            if (!empty($titleNews)) {
                $query->whereHas('title', function ($q) use ($titleNews) {
                    $q->where('name', 'like', '%' . $titleNews . '%');
                });
            }

            // Tambahkan filter berdasarkan status jika ada
            if (!empty($status)) {
                $query->where('status', $status);
            }

            // Lakukan pagination 10 item per halaman
            $news = $query->paginate(10);

            if ($news->isEmpty()) {
                return response()->json([
                    'message' => 'No news found.'
                ], 404);
            }

            return response()->json([
                'message' => 'All news successfully retrieved',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error while getting all news: ' . $e->getMessage());
            return response()->json([
                'errors' => 'An error occurred while getting all news.'
            ], 500);
        }
    }

    public function getAllNewsForPublic(Request $request)
    {
        try {
            // Ambil parameter query dari request
            $titleNews = $request->query('title');

            // Ambil semua data berita beserta nama pembuat dan tag-nya, dengan pagination 10 item per halaman
            $queryNews = News::with(['creator:name', 'newsTags:name']);

            if (!empty($titleNews)) {
                $queryNews->whereHas('title', function ($q) use ($titleNews) {
                    $q->where('name', 'like', '%' . $titleNews . '%');
                });
            }

            $news = $queryNews->where('status', 'published')->paginate(10);

            if ($news->isEmpty()) {
                return response()->json([
                    'message' => 'No news found.'
                ], 404);
            }

            return response()->json([
                'message' => 'News successfully retrieved',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while getting news: ' . $e->getMessage());
            return response()->json([
                'errors' => 'An error occured while getting news.'
            ], 500);
        }
    }

    public function getNewsById($id)
    {
        try {
            // Ambil berita berdasarkan ID beserta nama pembuat dan tag-nya
            $news = News::with([
                'creator:name',  // Ambil id dan name dari relasi creator (User)
                'newsTags:name'  // Ambil id dan name dari relasi newsTags (NewsTag)
            ])
                ->where('status', 'published')
                ->find($id);

            // Jika berita tidak ditemukan, kembalikan response 404
            if (!$news) {
                return response()->json([
                    'message' => 'News not found.'
                ], 404);
            }

            // Tambahkan jumlah viewer +1
            $news->increment('viewer');

            return response()->json([
                'message' => 'News successfully retrieved.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while getting news by ID: ' . $e->getMessage());
            return response()->json([
                'errors' => 'An error occurred while getting the news.'
            ], 500);
        }
    }

    public function getNewsDetailForAdmin($newsId)
    {
        try {
            // Ambil berita berdasarkan ID beserta nama pembuat dan tag-nya
            $news = News::with([
                'creator:id,name,email',  // Ambil id dan name dari relasi creator (User)
                'newsTags'  // Ambil id dan name dari relasi newsTags (NewsTag)
            ])->find($newsId);

            // Jika berita tidak ditemukan, kembalikan response 404
            if (!$news) {
                return response()->json([
                    'message' => 'News not found.'
                ], 404);
            }

            return response()->json([
                'message' => 'News detail successfully retrieved.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while getting news by ID: ' . $e->getMessage());
            return response()->json([
                'errors' => 'An error occurred while getting the news.'
            ], 500);
        }
    }

    public function createNews(Request $request)
    {
        // Cek apakah user yang login adalah admin
        $userLogin = Auth::user();
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi input
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'content' => 'required|string',
            'status' => 'nullable|in:published,archived',
            'thumbnail' => 'required',
            'news_tag_ids' => 'required|array',
        ], [
            'title.max' => 'News title cannot exceed more than 100 characters.',
            'status.in' => 'Status must be either published or archived.',
            'news_tag_ids.array' => 'news_tag_id must be an array of news tags.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $newsTagIdRequest = $request->news_tag_ids;
        $nonIntegerIds = [];

        Log::warning('REQUEST NEWS TAG ! $newsTagIdRequest', [
            'news_tag_ids' => $newsTagIdRequest
        ]);

        try {
            foreach ($newsTagIdRequest as $tagId) {
                $checkIntegerTag = is_int($tagId);
                if (!$checkIntegerTag) {
                    $nonIntegerIds[] = $checkIntegerTag;
                }
            }

            if (empty($nonIntegerIds)) {
                Log::error('Invalid news tag IDs detected. Please check decode hashed id middleware!', [
                    'context' => 'NewsController.php (createNews) News Tag ID is not an integer.',
                    'news_tag_ids' => $nonIntegerIds
                ]);

                return response()->json([
                    'errors' => 'Internal error has occurred. Please contact administrator of app.'
                ], 500);
            }

            $newsTags = NewsTag::whereIn('id', $newsTagIdRequest)->get();

            // Bandingkan ID yang ditemukan dengan yang diminta
            $foundNewsTagIds = $newsTags->pluck('id')->toArray();
            $notFoundTagIds = array_diff($newsTagIdRequest, $foundNewsTagIds);

            if (!empty($notFoundTagIds)) {
                Log::info('Non-existence news tag found: ' . implode(',', $notFoundTagIds));

                return response()->json([
                    'errors' => 'Some news tags were not found.',
                    'missing_news_tag_ids' => $notFoundTagIds,
                ], 404);
            }

            // Cek file atau string untuk thumbnail
            if ($request->hasFile('thumbnail') || is_string($request->thumbnail)) {
                if ($request->hasFile('thumbnail')) {
                    // Thumbnail adalah file, validasi tipe gambar dan ukurannya
                    $file = $request->file('thumbnail');
                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/svg'];
                    if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid image (jpeg or png).'
                        ], 422);
                    }

                    if ($file->getSize() > 5 * 1024 * 1024) {
                        return response()->json([
                            'errors' => 'The thumbnail must not be larger than 5MB.'
                        ], 422);
                    }

                    // Nama folder untuk menyimpan thumbnail
                    $thumbnailDirectory = 'news_thumbnail';

                    // Cek apakah folder news_thumbnail ada, jika tidak, buat folder tersebut
                    if (!Storage::exists($thumbnailDirectory)) {
                        Storage::makeDirectory($thumbnailDirectory);
                    }

                    // Simpan file thumbnail ke storage/app/news_thumbnail
                    $thumbnailPath = Storage::putFile($thumbnailDirectory, $file);
                } else if (is_string($request->thumbnail)) {
                    // Periksa apakah thumbnail adalah URL yang valid
                    if (!filter_var($request->thumbnail, FILTER_VALIDATE_URL)) {
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid URL.'
                        ], 422);
                    }

                    // Jika thumbnail adalah URL yang valid, simpan string URL tersebut
                    $thumbnailPath = $request->thumbnail;
                }
            } else {
                return response()->json([
                    'errors' => 'Thumbnail must be a file or string.'
                ], 422);
            }

            // Siapkan slug dari judul berita, tambahkan tanggal dengan timezone Jakarta
            $date = Carbon::now('Asia/Jakarta')->format('Y-m-d');
            $slug = Str::slug($request->title) . '-' . $date;

            DB::beginTransaction();

            // Simpan berita ke database
            $news = News::create([
                'created_by' => $userLogin->id,
                'title' => $request->title,
                'thumbnail' => $thumbnailPath,
                'slug' => $slug,
                'content' => $request->content,
                'viewer' => 0,  // viewer dimulai dari 0
                'status' => $request->status ?? 'archived'  // default status adalah archived
            ]);

            // Hubungkan berita dengan tag
            $news->newsTags()->sync($foundNewsTagIds);

            DB::commit();

            $news->load(['creator:id,name,email', 'newsTags']);

            return response()->json([
                'message' => 'News successfully created.',
                'news' => $news
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating news: ' . $e->getMessage());
            return response()->json([
                'errors' => 'Internal error occurred, please try again later.'
            ], 500);
        }
    }

    public function updateNews(Request $request, $id)
    {
        // Cek apakah user adalah admin
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi input
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:100',
            'content' => 'nullable|string',
            'status' => 'nullable|in:published,archived',
            'thumbnail' => 'nullable', // Thumbnail tidak wajib
            'news_tag_id' => 'nullable' // Tag opsional, tapi harus valid
        ], [
            'title.max' => 'News title cannot exceed more than 100 characters.',
            'status.in' => 'Status must be either published or archived.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('news_tag_id')) {
            $newsTagIdRequest = $request->news_tag_ids;
        } else {
            $newsTagIdRequest = null;
        }

        try {
            if (!is_null($newsTagIdRequest)) {
                // Periksa apakah ID sudah di decode dengan benar oleh middleware decode hashed id
                $nonIntegerIds = array_filter($newsTagIdRequest, function ($tagId) {
                    return !is_numeric($tagId);
                });

                if (!empty($nonIntegerIds)) {
                    Log::error('Invalid news tag IDs detected. Please check decode hashed id middleware!', [
                        'context' => 'NewsController.php (createNews) News Tag ID is not an integer.',
                        'news_tag_ids' => implode(',', $nonIntegerIds)
                    ]);

                    return response()->json([
                        'errors' => 'Internal error has occurred. Please contact administrator of app.'
                    ], 500);
                }

                $newsTags = NewsTag::whereIn('id', $newsTagIdRequest)->get();

                // Bandingkan ID yang ditemukan dengan yang diminta
                $foundNewsTagIds = $newsTags->pluck('id')->toArray();
                $notFoundTagIds = array_diff($newsTagIdRequest, $foundNewsTagIds);

                if (!empty($notFoundTagIds)) {
                    Log::info('Non-existence news tag found: ' . implode(',', $notFoundTagIds));

                    return response()->json([
                        'errors' => 'Some news tags were not found.',
                        'missing_news_tag_ids' => $notFoundTagIds,
                    ], 404);
                }
            }

            // Ambil berita berdasarkan ID
            $news = News::find($id);

            if (!$news) {
                return response()->json([
                    'errors' => 'News not found.'
                ], 404);
            }

            DB::beginTransaction();

            // Periksa apakah title, content, status, atau thumbnail diberikan input, jika tidak gunakan nilai lama
            $news->title = $request->input('title', $news->title);
            $news->content = $request->input('content', $news->content);
            $news->status = $request->input('status', $news->status);

            // Periksa apakah thumbnail ingin diubah
            if ($request->has('thumbnail')) {
                if ($request->hasFile('thumbnail')) {
                    // Thumbnail adalah file, periksa apakah valid
                    $file = $request->file('thumbnail');

                    // Periksa tipe file (jpeg/png)
                    $allowedMimeTypes = ['image/jpeg', 'image/png'];
                    if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
                        DB::rollBack();
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid image (jpeg or png).'
                        ], 422);
                    }

                    // Periksa ukuran file (maksimal 5 MB)
                    if ($file->getSize() > 5 * 1024 * 1024) {
                        DB::rollBack();
                        return response()->json([
                            'errors' => 'The thumbnail must not be larger than 5MB.'
                        ], 422);
                    }

                    // Simpan file thumbnail ke storage/app/news_thumbnail menggunakan nama asli file
                    $thumbnailPath = 'news_thumbnail/' . $file->getClientOriginalName();

                    // Cek jika folder belum ada
                    if (!Storage::exists('news_thumbnail')) {
                        Storage::makeDirectory('news_thumbnail');
                    }

                    Storage::put($thumbnailPath, file_get_contents($file));
                    $news->thumbnail = $thumbnailPath; // Simpan path thumbnail
                } elseif (is_string($request->thumbnail)) {
                    // Thumbnail adalah string, cek apakah ini URL valid
                    if (!filter_var($request->thumbnail, FILTER_VALIDATE_URL)) {
                        DB::rollBack();
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid URL.'
                        ], 422);
                    }

                    $news->thumbnail = $request->thumbnail; // Simpan URL thumbnail
                }
            }

            // Jika ada tag yang di-update, sinkronkan tag
            if (!is_null($newsTagIdRequest)) {
                $news->newsTags()->sync($foundNewsTagIds);
            }

            // Simpan perubahan
            $news->save();

            DB::commit();

            $news->load(['creator:id,name,email', 'newsTags']);

            return response()->json([
                'message' => 'News updated successfully.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occurred while updating news: ' . $e->getMessage());
            return response()->json([
                'errors' => 'An error occurred while updating the news.'
            ], 500);
        }
    }

    public function deleteNews($id)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ]);
        }

        try {
            $news = News::where('id', $id)->first();
            if ($news->isEmpty()) {
                Log::warning('Attempt to delete non-existence news with news ID: ' . $id);
                return response()->json([
                    'errors' => 'News not found.'
                ], 404);
            }

            DB::beginTransaction();

            DB::table('news_has_tags')->where('news_id', $news->id)->delete();

            // Cek jika thumbnail adalah path file di storage (tidak berupa URL)
            if ($news->thumbnail && !filter_var($news->thumbnail, FILTER_VALIDATE_URL)) {
                // Pastikan file thumbnail masih ada di storage sebelum menghapusnya
                if (Storage::exists($news->thumbnail)) {
                    Storage::delete($news->thumbnail);
                    Log::info('Thumbnail deleted: ' . $news->thumbnail);
                } else {
                    Log::warning('Thumbnail file not found in storage: ' . $news->thumbnail);
                }
            }

            $news->delete();

            DB::commit();

            return response()->json([
                'message' => 'News deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occured while deleting news: ' . $e->getMessage());
            return response()->json([
                'errors' => 'An error occurred while deleting the news.'
            ], 500);
        }
    }

    public function changeStatus(Request $request, $newsId)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:published,archived',
        ], [
            'status.in' => 'Status must be either published or archived.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $news = News::where('id', $newsId)->first();

            if ($news->isEmpty()) {
                Log::warning('Attempt to change status of non-existence news with news ID: ' . $newsId);
                return response()->json([
                    'errors' => 'News not found.'
                ], 404);
            }

            DB::beginTransaction();

            $news->status = $request->status;

            $news->save();

            DB::commit();

            $news->load(['creator:id,name,email', 'newsTags']);

            return response()->json([
                'message' => 'News status changed successfully.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occured while changing news status: ' . $e->getMessage());
            return response()->json([
                'errors' => 'An error occurred while changing the news status.'
            ], 500);
        }
    }
}
