jQuery(document).ready(function($) {
    $('nav a').on('click', function(e) {
        let href = $(this).attr('href');
        // Check if URL has 'paged' parameter
        if (href && href.includes('paged=')) {
            e.preventDefault();

            // Check if href is relative or absolute and create a proper URL
            if (!href.startsWith('http')) {
                // Make href an absolute URL by adding the current origin
                href = window.location.origin + href;
            }

            // Create URL object
            let url = new URL(href);
            let params = new URLSearchParams(url.search);
            
            // Remove paged parameter
            params.delete('paged');
            
            // Reconstruct URL
            url.search = params.toString();
            
            // Redirect to new URL
            window.location.href = url.toString();
        }
    });
});
