<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $article->title }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; color: #1f2933; background: #fff; line-height: 1.6; }
        main { max-width: 52rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
        h1 { font-size: 2.25rem; line-height: 1.2; margin: 1.25rem 0 0.5rem; }
        .hero { display: block; width: 100%; height: auto; border-radius: 0.5rem; }
        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 0.75rem; margin-top: 2rem; padding: 0; list-style: none; }
        .gallery img { display: block; width: 100%; height: auto; border-radius: 0.375rem; }
    </style>
</head>
<body>
<main>
    <article>
        @if ($hero)
            {{-- Alt text is required by the library, so it is never empty. The 1200x630 crop keeps the focal point in frame. --}}
            <img class="hero" src="{{ $heroSrc }}" srcset="{{ $heroSrcset }}" sizes="(min-width: 52rem) 52rem, 100vw" width="1200" height="630" alt="{{ $hero->alt_text }}">
        @endif
        <h1>{{ $article->title }}</h1>
        <div>{!! nl2br(e($article->body)) !!}</div>

        @if ($gallery->isNotEmpty())
            <ul class="gallery" aria-label="Gallery">
                @foreach ($gallery as $image)
                    <li><img src="{{ $image['src'] }}" width="400" height="300" loading="lazy" alt="{{ $image['alt'] }}"></li>
                @endforeach
            </ul>
        @endif
    </article>
</main>
</body>
</html>
