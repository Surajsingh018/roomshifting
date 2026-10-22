<?php

$pageTitle = 'RoomShift | Clear, considered moving';
$pageMeta = 'Plan your next move with a clear quote, an attentive team, and updates at every step.';
include __DIR__ . '/includes/header.php';
$serviceResult = web_catalog(static fn() => web_cms()->listServices(['limit' => 100]));
$bannerResult = web_catalog(static fn() => web_cms()->listBanners(['limit' => 10]));
$offerResult = web_catalog(static fn() => web_cms()->listOffers(['limit' => 10]));
include __DIR__ . '/includes/navbar.php';
$services = $serviceResult['items'] ?? [];
$banners = $bannerResult['items'] ?? [];
$offers = $offerResult['items'] ?? [];
$heroSlides = array_slice($services, 0, 4);
?><main>
  <section class="hero-section">
    <div class="container py-5 py-lg-6">
      <div class="row align-items-center g-4">
        <div class="col-lg-7">
          <p class="eyebrow">A better way to move</p>
          <h1 class="display-4 fw-bold">Make your next move feel <span class="text-sage">lighter.</span></h1>
          <p class="lead mt-3">A thoughtful moving experience, with clear steps and pricing calculated for your move.</p>
          <div class="d-flex flex-wrap gap-3 mt-4"><a class="btn btn-gold btn-lg" href="quote.php"><?= e($t(
                                                                                                      'quote',
                                                                                                    )) ?></a><a class="btn btn-outline-dark btn-lg" href="how-it-works.php"><?= e($t(
                                                                          'how',
                                                                        )) ?></a></div>
          <div class="hero-assurance mt-4"><span>Clear quote</span><span>Careful handling</span><span>Track your move</span></div>
        </div>
        <div class="col-lg-5">
          <?php if (
            count($heroSlides) > 1
          ): ?><div id="heroCarousel" class="carousel slide hero-carousel" data-bs-ride="carousel" data-bs-interval="7000" data-bs-pause="hover" data-bs-keyboard="true" data-bs-touch="true" aria-roledescription="carousel" aria-label="RoomShift moving services">
              <div class="carousel-indicators"><?php foreach ($heroSlides as $index => $slide): ?><button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="<?= (int) $index ?>" class="<?= $index
                                                                                                                                                                                                        === 0
                                                                                                                                                                                                        ? 'active'
                                                                                                                                                                                                        : '' ?>" <?= $index === 0 ? 'aria-current="true"' : '' ?> aria-label="Slide <?= (int) ($index + 1) ?>: <?= e(
                                                                                                                  $slide['name'],
                                                                                                                ) ?>"></button><?php endforeach; ?></div>
              <div class="carousel-inner"><?php foreach ($heroSlides as $index => $slide): ?><div class="carousel-item <?= $index
                                                                                                                          === 0
                                                                                                                          ? 'active'
                                                                                                                          : '' ?>" aria-roledescription="slide" aria-label="<?= (int) ($index + 1) ?> of <?= count(
                                                                                          $heroSlides,
                                                                                        ) ?>">
                    <article class="hero-slide-card">
                      <p class="eyebrow">Service <?= (int) ($index + 1) ?> of <?= count(
                                                                                $heroSlides,
                                                                              ) ?></p>
                      <h2><?= e($slide['name']) ?></h2>
                      <p><?= e(
                                              $slide['description'],
                                            ) ?></p><a class="btn btn-gold" href="quote.php?service_id=<?= (int) $slide['id'] ?>">Get a quote</a>
                    </article>
                  </div><?php endforeach; ?></div>
              <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev" aria-label="Previous service"><span class="carousel-control-prev-icon" aria-hidden="true"></span></button><button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next" aria-label="Next service"><span class="carousel-control-next-icon" aria-hidden="true"></span></button>
            </div><?php elseif (
                  $heroSlides
                ): ?><div class="hero-art" aria-hidden="true"><span class="hero-art-box hero-art-box-one"></span><span class="hero-art-box hero-art-box-two"></span><span class="hero-art-sun"></span></div><?php else: ?><div class="empty-panel" role="status"><?= e($t(
                                                                                                                                                                                                                                                        'empty',
                                                                                                                                                                                                                                                      )) ?></div><?php endif; ?>
        </div>
      </div>
    </div>
  </section>
  <?php if (
    $GLOBALS['webDataError']
  ): ?><div class="container pt-4">
      <div class="alert alert-warning" role="status"><?= e($t(
                                                        'error',
                                                      )) ?></div>
    </div><?php endif; ?>
  <?php if (
    $banners
  ): ?><section class="container py-4" aria-label="Current campaigns"><?php foreach ($banners as $banner): ?><article class="campaign-card mb-3">
          <div class="row align-items-center g-0">
            <div class="col-md-8 p-4">
              <p class="eyebrow mb-2">Current campaign</p>
              <h2 class="h3"><?= e(
                                                                        $banner['title'],
                                                                      ) ?></h2><?php if ($banner['body']): ?><p class="mb-2"><?= e($banner['body']) ?></p><?php endif; ?><?php if (
                                                                                                      $banner['ends_at']
                                                                                                    ): ?><small>Available until <?= e(date(
                                                                                                        'M j, Y',
                                                                                                        strtotime($banner['ends_at']),
                                                                                                      )) ?></small><?php endif; ?>
            </div><?php if (
                                                                        $banner['image_path']
                                                                      ): ?><div class="col-md-4"><img class="campaign-image" src="<?= e(
                                                                          $banner['image_path'],
                                                                        ) ?>" alt="Campaign artwork for <?= e(
                                                                          $banner['title'],
                                                                        ) ?>" loading="lazy" decoding="async"></div><?php endif; ?>
          </div>
        </article><?php endforeach; ?></section><?php endif; ?>
  <section class="container section-space" aria-labelledby="servicesHeading">
    <div class="section-heading">
      <div>
        <p class="eyebrow">Move with confidence</p>
        <h2 id="servicesHeading">Services shaped around your move</h2>
      </div><a href="services.php" class="text-link">Explore all services</a>
    </div><?php if (
            !$services
          ): ?><div class="empty-panel"><?= e($t(
                                'empty',
                              )) ?></div><?php else: ?><div class="row g-4"><?php foreach ($services as $service): ?><div class="col-md-6 col-xl-4">
            <article class="service-card h-100">
              <div class="service-card-mark" aria-hidden="true">↗</div>
              <h3 class="h4"><?= e(
                                                  $service['name'],
                                                ) ?></h3>
              <p><?= e(
                                                  $service['description'],
                                                ) ?></p><a class="btn btn-outline-dark mt-auto" href="quote.php?service_id=<?= (int) $service['id'] ?>">Get a quote <span aria-hidden="true">→</span></a>
            </article>
          </div><?php endforeach; ?></div><?php endif; ?>
  </section>
  <?php if (
    $offers
  ): ?><section class="offers-band py-5">
      <div class="container">
        <p class="eyebrow">Seasonal offers</p>
        <h2 class="h2 mb-4">Available campaigns</h2>
        <div class="row g-3"><?php foreach ($offers as $offer): ?><div class="col-md-6 col-lg-4">
              <article class="offer-card h-100">
                <h3 class="h5"><?= e(
                                  $offer['title'],
                                ) ?></h3>
                <p><?= e($offer['description']) ?></p><?php if ($offer['ends_at']): ?><small>Offer ends <?= e(date(
                                                                                                          'M j, Y',
                                                                                                          strtotime($offer['ends_at']),
                                                                                                        )) ?></small><?php endif; ?>
              </article>
            </div><?php endforeach; ?></div>
      </div>
    </section><?php endif; ?>
  <section class="container section-space">
    <div class="how-panel">
      <div>
        <p class="eyebrow">A clear path forward</p>
        <h2>From first details to moving day</h2>
        <p>Share what you are moving. We calculate the quote from your move details and current service pricing, then you can confirm a booking.</p><a class="btn btn-gold" href="how-it-works.php">See how it works</a>
      </div>
      <div class="how-number" aria-hidden="true">01—03</div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php';
