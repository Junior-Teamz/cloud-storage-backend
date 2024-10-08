<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsTag;
use Illuminate\Support\Str;
use App\Services\CheckAdminService;
use App\Services\GenerateURLService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class NewsController extends Controller
{
    protected $checkAdminService;
    protected $generateImageURL;

    // Inject RoleService ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService, GenerateURLService $generateURLService)
    {
        $this->checkAdminService = $checkAdminService;
        $this->generateImageURL = $generateURLService;
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
            $query = News::with(['creator:id,uuid,name,email', 'creator.instances:uuid,name,address', 'newsTags:id,uuid,name']);

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
                    'message' => 'No news found.',
                    'data' => []
                ], 200);
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
            $queryNews = News::with(['creator:id,uuid,name', 'creator.instances:name,address', 'newsTags:name']);

            if (!empty($titleNews)) {
                $queryNews->whereHas('title', function ($q) use ($titleNews) {
                    $q->where('name', 'like', '%' . $titleNews . '%');
                });
            }

            $news = $queryNews->where('status', 'published')->paginate(10);

            if ($news->isEmpty()) {
                return response()->json([
                    'message' => 'No news found.',
                    'data' => []
                ], 200);
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

    public function getNewsById(Request $request, $id)
    {
        try {
            // Ambil berita berdasarkan ID beserta nama pembuat dan tag-nya
            $news = News::with([
                'creator:id,uuid,name',  // Ambil id dan name dari relasi creator (User)
                'creator.instances:name,address',
                'newsTags:name'  // Ambil id dan name dari relasi newsTags (NewsTag)
            ])
                ->where('status', 'published')
                ->where('uuid', $id)->first();

            // Jika berita tidak ditemukan, kembalikan response 404
            if (!$news) {
                return response()->json([
                    'message' => 'News not found.',
                    'data' => []
                ], 200);
            }

            $origin = $request->header('Origin', '');
            // jika permintaan mengambil informasi berita dari frontend, tambahkan count viewers
            if (in_array($origin, config('frontend.url_for_cors'))) {
                $news->increment('viewers');
            }

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

    // public function addNewsViewers(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'news_id' => 'required|string|exists:news,uuid'
    //     ]);

    //     if($validator->fails()){
    //         return response()->json([
    //             'errors' => $validator->errors()
    //         ]);
    //     }
    //     try {
    //         $newsIdRequest = $request->news_id;
    //         $news = News::where('uuid', $newsIdRequest)->first();

    //         DB::beginTransaction();
    //         $news->increment('viewer');
    //         $news->save();
    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Successfully adding viewers count to the news.'
    //         ], 200);

    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         Log::error('Error occurred while adding viewers count to the news', [
    //             'news_id' => $newsIdRequest,
    //             'message' => $e->getMessage()
    //         ]);
    //         return response()->json([
    //             'An error occurred while adding viewers count to the news.'
    //         ], 500);
    //     }
    // }

    public function getNewsBySlug($slug)
    {
        if (!is_string($slug)) {
            return response()->json([
                'errors' => 'Parameter must be a slug of news.'
            ], 400);
        }

        try {
            // Ambil berita berdasarkan ID beserta nama pembuat dan tag-nya
            $news = News::with([
                'creator:id,uuid,name',  // Ambil id dan name dari relasi creator (User)
                'creator.instances:name,address',
                'newsTags:name'  // Ambil id dan name dari relasi newsTags (NewsTag)
            ])
                ->where('status', 'published')
                ->where('slug', $slug)->first();

            // Jika berita tidak ditemukan, kembalikan response 200
            if (!$news) {
                return response()->json([
                    'message' => 'News not found.',
                    'data' => []
                ], 200);
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
                'creator:id,uuid,name,email',  // Ambil id dan name dari relasi creator (User)
                'creator.instances:uuid,name,address',
                'newsTags:id,uuid,name'  // Ambil id dan name dari relasi newsTags (NewsTag)
            ])->where('uuid', $newsId)->first();

            // Jika berita tidak ditemukan, kembalikan response 404
            if (!$news) {
                return response()->json([
                    'message' => 'News not found.',
                    'data' => []
                ], 200);
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

        try {
            $newsTags = NewsTag::whereIn('uuid', $newsTagIdRequest)->get();

            // Bandingkan ID yang ditemukan dengan yang diminta
            $foundNewsTagIds = $newsTags->pluck('id')->toArray();
            $foundNewsTagIdsToCheck = $newsTags->pluck('uuid')->toArray();
            $notFoundTagIds = array_diff($newsTagIdRequest, $foundNewsTagIdsToCheck);

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

                    $allowedMimeTypes = ['image/jpeg', 'image/png'];
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

                    // Cek apakah folder news_thumbnail ada di disk public, jika tidak, buat folder tersebut
                    if (!Storage::disk('public')->exists($thumbnailDirectory)) {
                        Storage::disk('public')->makeDirectory($thumbnailDirectory);
                    }

                    // Simpan file thumbnail ke storage/app/public/news_thumbnail
                    $thumbnailPath = $file->store($thumbnailDirectory, 'public');

                    // Buat URL publik untuk thumbnail
                    $thumbnailUrl = Storage::disk('public')->url($thumbnailPath);
                    
                } else if (is_string($request->thumbnail)) {
                    // Periksa apakah thumbnail adalah URL yang valid
                    if (!filter_var($request->thumbnail, FILTER_VALIDATE_URL)) {
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid URL.'
                        ], 422);
                    }

                    // Jika thumbnail adalah URL yang valid, simpan string URL tersebut
                    $thumbnailUrl = $request->thumbnail;
                }
            } else {
                return response()->json([
                    'errors' => 'Thumbnail must be a file or string.'
                ], 422);
            }

            // Siapkan slug dari judul berita, tambahkan tanggal dengan timezone Jakarta
            $date = Carbon::now('Asia/Jakarta')->format('Y-m-d');

            // Bersihkan tag HTML dari judul berita menggunakan strip_tags
            $cleanedTitle = strip_tags($request->title);

            // Buat slug dari judul yang sudah dibersihkan
            $slug = Str::slug($cleanedTitle) . '-' . $date;

            DB::beginTransaction();

            // Simpan berita ke database
            $news = News::create([
                'created_by' => $userLogin->id,
                'title' => $request->title,
                'thumbnail_path' => $thumbnailPath ?? null,
                'thumbnail_url' => $thumbnailUrl,
                'slug' => $slug,
                'content' => $request->content,
                'viewer' => 0,  // viewer dimulai dari 0
                'status' => $request->status ?? 'archived'  // default status adalah archived
            ]);

            // Hubungkan berita dengan tag
            $news->newsTags()->sync($foundNewsTagIds);

            DB::commit();

            $news->load(['creator:id,uuid,name,email', 'creator.instances:uuid,name,address', 'newsTags:id,uuid,name']);

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
                $newsTags = NewsTag::whereIn('uuid', $newsTagIdRequest)->get();

                // Bandingkan ID yang ditemukan dengan yang diminta
                $foundNewsTagIds = $newsTags->pluck('id')->toArray();
                $foundNewsTagIdsToCheck = $newsTags->pluck('uuid')->toArray();
                $notFoundTagIds = array_diff($newsTagIdRequest, $foundNewsTagIdsToCheck);

                if (!empty($notFoundTagIds)) {
                    Log::info('Non-existence news tag found: ' . implode(',', $notFoundTagIds));

                    return response()->json([
                        'errors' => 'Some news tags were not found.',
                        'missing_news_tag_ids' => $notFoundTagIds,
                    ], 404);
                }
            }

            // Ambil berita berdasarkan ID
            $news = News::where('uuid', $id)->first();

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

                    // Nama folder untuk menyimpan thumbnail
                    $thumbnailDirectory = 'news_thumbnail';

                    // Cek apakah folder news_thumbnail ada di disk public, jika tidak, buat folder tersebut
                    if (!Storage::disk('public')->exists($thumbnailDirectory)) {
                        Storage::disk('public')->makeDirectory($thumbnailDirectory);
                    }

                    // Cek apakah ada thumbnail lama dan hapus jika ada
                    if ($news->thumbnail_path && Storage::disk('public')->exists($news->thumbnail_path)) {
                        Storage::disk('public')->delete($news->thumbnail_path);
                    }

                    // Simpan file thumbnail ke storage/app/public/news_thumbnail
                    $thumbnailPath = $file->store($thumbnailDirectory, 'public');

                    // Buat URL publik untuk thumbnail
                    $thumbnailUrl = Storage::disk('public')->url($thumbnailPath);

                    // Simpan path dan URL thumbnail ke dalam model
                    $news->thumbnail_path = $thumbnailPath;
                    $news->thumbnail_url = $thumbnailUrl;
                } elseif (is_string($request->thumbnail)) {
                    // Thumbnail adalah string, cek apakah ini URL valid
                    if (!filter_var($request->thumbnail, FILTER_VALIDATE_URL)) {
                        DB::rollBack();
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid URL.'
                        ], 422);
                    }

                    // Jika sebelumnya ada thumbnail file, hapus file lama
                    if ($news->thumbnail_path && Storage::disk('public')->exists($news->thumbnail_path)) {
                        Storage::disk('public')->delete($news->thumbnail_path);
                        $news->thumbnail_path = null; // Setel path ke null
                    }

                    // Simpan URL thumbnail baru
                    $news->thumbnail_url = $request->thumbnail;
                }
            }

            // Jika ada tag yang di-update, sinkronkan tag
            if (!is_null($newsTagIdRequest)) {
                $news->newsTags()->sync($foundNewsTagIds);
            }

            // Simpan perubahan
            $news->save();

            DB::commit();

            $news->load(['creator:id,uuid,name,email', 'creator.instances:uuid,name,address', 'newsTags:id,uuid,name']);

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
            $news = News::where('uuid', $id)->first();
            if (!$news) {
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
            $news = News::where('uuid', $newsId)->first()->first();

            if (!$news) {
                Log::warning('Attempt to change status of non-existence news with news ID: ' . $newsId);
                return response()->json([
                    'errors' => 'News not found.'
                ], 404);
            }

            DB::beginTransaction();

            $news->status = $request->status;

            $news->save();

            DB::commit();

            $news->load(['creator:id,uuid,name,email', 'creator.instances:uuid,name,address', 'newsTags:id,uuid,name']);

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
