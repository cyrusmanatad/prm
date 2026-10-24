<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $kit->title }} · WeavDash</title>
    <meta name="description" content="{{ mb_substr($kit->bio, 0, 160) }}">
    <link rel="stylesheet" href="/portal-assets/fonts.css">
    <style>
      body { font-family: Manrope, ui-sans-serif, system-ui, sans-serif; background: #fbf7ef; color: #221a26; margin: 0; }
      main { max-width: 50rem; margin: 3rem auto; padding: 1.5rem; }
      p { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.6; color: #4a3f4f; }
      a { color: #8e1650; overflow-wrap: anywhere; }
      h1, h2 { font-family: "Bricolage Grotesque", Manrope, ui-sans-serif, system-ui, sans-serif; overflow-wrap: anywhere; }
      li { margin-bottom: 1rem; }
    </style>
  </head>
  <body>
    <main>
      <p>WeavDash · Media kit</p>
      <h1>{{ $kit->title }}</h1>
      <p>{{ $kit->bio }}</p>
      @if ($kit->website)
        <a href="{{ $kit->website }}" rel="noopener noreferrer">Visit website</a>
      @endif
      @if ($kit->social_links)
        <section aria-labelledby="social-title">
          <h2 id="social-title">Social accounts</h2>
          <ul>
            @foreach ($kit->social_links as $social)
              <li><a href="{{ $social['url'] }}" rel="noopener noreferrer">{{ $social['label'] }}</a></li>
            @endforeach
          </ul>
        </section>
      @endif
    </main>
  </body>
</html>
