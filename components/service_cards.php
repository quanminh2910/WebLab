<?php foreach ($services ?? [] as $service): ?>
    <?php
    // Keep the existing browser favorite keys, including "and" for "&".
    $favoriteName = str_replace('&', 'and', $service['name']);
    ?>
    <article class="service-card col-12 col-md-6 col-lg-4" data-category="<?= escapeHtml($service['category_slug']) ?>">
        <div class="card h-100">
            <button type="button" class="favorite-button" data-service="<?= escapeHtml($favoriteName) ?>"
                    aria-pressed="false" aria-label="Add <?= escapeHtml($favoriteName) ?> to favorites">
                <span aria-hidden="true">&#9825;</span>
            </button>
            <img src="images/<?= escapeHtml(rawurlencode(basename($service['image']))) ?>"
                 class="card-img-top" alt="<?= escapeHtml($service['name']) ?>">
            <div class="card-body d-flex flex-column">
                <h3 class="card-title h5"><?= escapeHtml($service['name']) ?></h3>
                <p class="card-text"><?= escapeHtml($service['short_description']) ?></p>
                <p class="text-muted mb-2">Duration: <?= escapeHtml(formatServiceDuration($service)) ?></p>
                <p class="fw-bold"><?= escapeHtml(formatServicePrice($service)) ?></p>
                <a href="../pages/item_details.php?service=<?= escapeHtml(rawurlencode($service['slug'])) ?>"
                   class="btn btn-primary mt-auto">
                    View <?= escapeHtml($service['name']) ?>
                </a>
            </div>
        </div>
    </article>
<?php endforeach; ?>
