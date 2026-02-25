<?php
/**
 * Shofar Scripture Widget - Animated Scripture of the Day
 * 
 * Automatically appears on every page for logged-in users.
 * Positioned as a floating widget at the bottom-right corner.
 * 
 * Features:
 * - Sound-wave ribbon with scripture text flowing from shofar
 * - Wind/flutter effect on attire using SVG filters
 * - Daily rotating verses (31 scriptures)
 * - Slide-up animation from bottom corner
 * - Click to expand/collapse
 * - Fully responsive
 * - Respects prefers-reduced-motion
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * CONFIGURATION
 * ============================================================
 */

// Image URL - Update this to your uploaded image
define('SHOFAR_IMAGE_URL', 'https://rcncalifornia.org/staging2/wp-content/uploads/2026/02/ChatGPT-Image-Feb-3-2026-01_42_44-AM.png');

// Horn opening position (where sound wave starts) - percentage of image dimensions
define('SHOFAR_HORN_X', 18);      // % from left
define('SHOFAR_HORN_Y', 12);      // % from top

// Sound wave path control points (Bezier curve) - percentages
define('SHOFAR_WAVE_CP1_X', 5);   // First control point X
define('SHOFAR_WAVE_CP1_Y', 25);  // First control point Y
define('SHOFAR_WAVE_CP2_X', 35);  // Second control point X
define('SHOFAR_WAVE_CP2_Y', 5);   // Second control point Y
define('SHOFAR_WAVE_END_X', 2);   // End point X
define('SHOFAR_WAVE_END_Y', 45);  // End point Y

/**
 * Attire/Cloak polygon points for flutter effect
 */
function shofar_get_attire_polygon_points() {
    return [
        [52, 28], [75, 25], [85, 45], [80, 70],
        [75, 95], [55, 98], [40, 95], [35, 75],
        [38, 50], [45, 35],
    ];
}

/**
 * Daily scriptures array
 */
function shofar_get_scriptures() {
    return [
        ['title' => 'Call to Worship', 'text' => 'Make a joyful noise unto the LORD, all the earth.', 'ref' => 'Psalm 98:4'],
        ['title' => 'The Trumpet Sound', 'text' => 'Blow the trumpet in Zion, and sound an alarm in my holy mountain.', 'ref' => 'Joel 2:1'],
        ['title' => 'Day of the Lord', 'text' => 'The great day of the LORD is near, it is near, and hasteth greatly.', 'ref' => 'Zephaniah 1:14'],
        ['title' => 'His Voice', 'text' => 'The voice of the LORD is powerful; the voice of the LORD is full of majesty.', 'ref' => 'Psalm 29:4'],
        ['title' => 'Praise Him', 'text' => 'Praise Him with the sound of the trumpet.', 'ref' => 'Psalm 150:3'],
        ['title' => 'Shout for Joy', 'text' => 'Shout for joy to the LORD, all the earth, burst into jubilant song.', 'ref' => 'Psalm 98:4'],
        ['title' => 'Awake', 'text' => 'Awake, awake, put on strength, O arm of the LORD.', 'ref' => 'Isaiah 51:9'],
        ['title' => 'New Song', 'text' => 'Sing unto the LORD a new song, and his praise from the end of the earth.', 'ref' => 'Isaiah 42:10'],
        ['title' => 'Trumpet Call', 'text' => 'In a moment, in the twinkling of an eye, at the last trump.', 'ref' => '1 Corinthians 15:52'],
        ['title' => 'Assembly', 'text' => 'Gather the people, sanctify the congregation, assemble the elders.', 'ref' => 'Joel 2:16'],
        ['title' => 'Victory', 'text' => 'Thanks be to God, which giveth us the victory through our Lord.', 'ref' => '1 Corinthians 15:57'],
        ['title' => 'Rejoice', 'text' => 'Rejoice in the LORD always: and again I say, Rejoice.', 'ref' => 'Philippians 4:4'],
        ['title' => 'His Glory', 'text' => 'The heavens declare the glory of God.', 'ref' => 'Psalm 19:1'],
        ['title' => 'Strength', 'text' => 'The LORD is my strength and my shield.', 'ref' => 'Psalm 28:7'],
        ['title' => 'Praise', 'text' => 'Let every thing that hath breath praise the LORD.', 'ref' => 'Psalm 150:6'],
        ['title' => 'His Word', 'text' => 'So shall my word be that goeth forth out of my mouth.', 'ref' => 'Isaiah 55:11'],
        ['title' => 'Arise', 'text' => 'Arise, shine; for thy light is come.', 'ref' => 'Isaiah 60:1'],
        ['title' => 'Proclaim', 'text' => 'The Spirit of the Lord GOD is upon me.', 'ref' => 'Isaiah 61:1'],
        ['title' => 'Hear', 'text' => 'Hear, O Israel: The LORD our God is one LORD.', 'ref' => 'Deuteronomy 6:4'],
        ['title' => 'Remember', 'text' => 'Remember the former things of old: for I am God.', 'ref' => 'Isaiah 46:9'],
        ['title' => 'Trust', 'text' => 'Trust in the LORD with all thine heart.', 'ref' => 'Proverbs 3:5'],
        ['title' => 'Peace', 'text' => 'Thou wilt keep him in perfect peace.', 'ref' => 'Isaiah 26:3'],
        ['title' => 'Hope', 'text' => 'But they that wait upon the LORD shall renew their strength.', 'ref' => 'Isaiah 40:31'],
        ['title' => 'Faith', 'text' => 'Now faith is the substance of things hoped for.', 'ref' => 'Hebrews 11:1'],
        ['title' => 'Love', 'text' => 'The greatest of these is love.', 'ref' => '1 Corinthians 13:13'],
        ['title' => 'Grace', 'text' => 'For by grace are ye saved through faith.', 'ref' => 'Ephesians 2:8'],
        ['title' => 'Mercy', 'text' => 'His compassions fail not. They are new every morning.', 'ref' => 'Lamentations 3:22-23'],
        ['title' => 'Salvation', 'text' => 'The LORD is my light and my salvation.', 'ref' => 'Psalm 27:1'],
        ['title' => 'Deliverance', 'text' => 'He delivered me, because he delighted in me.', 'ref' => 'Psalm 18:19'],
        ['title' => 'Blessing', 'text' => 'The LORD bless thee, and keep thee.', 'ref' => 'Numbers 6:24'],
        ['title' => 'Eternal', 'text' => 'For God so loved the world, that he gave his only begotten Son.', 'ref' => 'John 3:16'],
    ];
}

/**
 * Get today's scripture based on day of year
 */
function shofar_get_todays_scripture() {
    $scriptures = shofar_get_scriptures();
    $day_of_year = date('z');
    $index = $day_of_year % count($scriptures);
    return $scriptures[$index];
}

/**
 * ============================================================
 * AUTO-LOAD FOR LOGGED-IN USERS
 * ============================================================
 */
add_action('wp_footer', function() {
    // Only show for logged-in users
    if (!is_user_logged_in()) {
        return;
    }
    
    // Don't show in admin
    if (is_admin()) {
        return;
    }
    
    $scripture = shofar_get_todays_scripture();
    $image_url = SHOFAR_IMAGE_URL;
    
    // Build wave path
    $wave_path = sprintf(
        'M %d %d C %d %d, %d %d, %d %d',
        SHOFAR_HORN_X, SHOFAR_HORN_Y,
        SHOFAR_WAVE_CP1_X, SHOFAR_WAVE_CP1_Y,
        SHOFAR_WAVE_CP2_X, SHOFAR_WAVE_CP2_Y,
        SHOFAR_WAVE_END_X, SHOFAR_WAVE_END_Y
    );
    
    // Attire polygon
    $attire_points = shofar_get_attire_polygon_points();
    $polygon_str = implode(' ', array_map(function($p) {
        return "{$p[0]}%,{$p[1]}%";
    }, $attire_points));
    ?>

<!-- ============================================================
     SHOFAR SCRIPTURE WIDGET - Styles
     ============================================================ -->
<style id="shofar-widget-styles">
/* ===== FLOATING WIDGET CONTAINER ===== */
.shofar-widget {
  position: fixed;
  bottom: -500px;
  right: 20px;
  z-index: 9999;
  transition: bottom 0.8s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.5s ease;
  cursor: pointer;
  filter: drop-shadow(0 10px 30px rgba(0, 0, 0, 0.3));
}

.shofar-widget.is-visible {
  bottom: -120px;
}

.shofar-widget.is-expanded {
  bottom: 20px;
}

/* Completely hidden/dismissed */
.shofar-widget.is-closed {
  bottom: -600px !important;
  opacity: 0;
  pointer-events: none;
}

.shofar-widget:hover {
  filter: drop-shadow(0 15px 40px rgba(0, 0, 0, 0.35));
}

.shofar-widget__inner {
  position: relative;
  width: 320px;
  height: 480px;
}

.shofar-widget__image {
  width: 100%;
  height: 100%;
  object-fit: contain;
  pointer-events: none;
  border-radius: 12px;
}

/* ===== SCRIPTURE OVERLAY ON BANNER ===== */
.shofar-widget__scripture {
  position: absolute;
  top: 58px;
  left: 8px;
  width: 108px;
  height: 145px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-align: center;
  padding: 10px 8px;
  transform: rotate(-2deg);
  pointer-events: none;
}

.shofar-widget__title {
  font-family: 'Cinzel', Georgia, serif;
  font-size: 9px;
  font-weight: 700;
  color: #2D1810;
  letter-spacing: 1px;
  text-transform: uppercase;
  margin-bottom: 6px;
  text-shadow: 0 1px 1px rgba(255,255,255,0.5);
}

.shofar-widget__text {
  font-family: 'IM Fell English', Georgia, serif;
  font-size: 9px;
  line-height: 1.4;
  color: #3D2415;
  font-style: italic;
  margin-bottom: 8px;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 5;
  -webkit-box-orient: vertical;
}

.shofar-widget__ref {
  font-family: 'Cinzel', Georgia, serif;
  font-size: 7px;
  font-weight: 600;
  color: #5D3A20;
  letter-spacing: 0.5px;
}

/* ===== GOLDEN GLOW AT HORN ===== */
.shofar-widget__glow {
  position: absolute;
  width: 70px;
  height: 70px;
  left: <?php echo SHOFAR_HORN_X; ?>%;
  top: <?php echo SHOFAR_HORN_Y; ?>%;
  transform: translate(-50%, -50%);
  background: radial-gradient(circle, rgba(255, 200, 100, 0.7) 0%, transparent 70%);
  border-radius: 50%;
  animation: shofarGlow 2s ease-in-out infinite;
  pointer-events: none;
}

@keyframes shofarGlow {
  0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.6; }
  50% { transform: translate(-50%, -50%) scale(1.4); opacity: 0.9; }
}

/* ===== HINT TOOLTIP ===== */
.shofar-widget__hint {
  position: absolute;
  bottom: 115px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(0, 0, 0, 0.75);
  color: #fff;
  font-size: 11px;
  padding: 6px 14px;
  border-radius: 20px;
  white-space: nowrap;
  opacity: 0;
  transition: opacity 0.3s ease;
  pointer-events: none;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.shofar-widget:hover .shofar-widget__hint {
  opacity: 1;
}

.shofar-widget.is-expanded .shofar-widget__hint {
  display: none;
}

/* ===== CLOSE/DISMISS BUTTON ===== */
.shofar-widget__close {
  position: absolute;
  top: -12px;
  right: -12px;
  width: 32px;
  height: 32px;
  background: #DC2626;
  border: 3px solid #fff;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  font-weight: bold;
  color: #fff;
  opacity: 0;
  transition: opacity 0.3s ease, transform 0.2s ease, background 0.2s ease;
  box-shadow: 0 3px 12px rgba(0, 0, 0, 0.3);
  z-index: 10;
}

/* Show close button when visible or expanded */
.shofar-widget.is-visible .shofar-widget__close,
.shofar-widget.is-expanded .shofar-widget__close {
  opacity: 1;
}

.shofar-widget__close:hover {
  background: #B91C1C;
  transform: scale(1.15);
}

/* ===== SOUND WAVE PARTICLES ===== */
.shofar-widget__particles {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  overflow: hidden;
}

.shofar-particle {
  position: absolute;
  width: 6px;
  height: 6px;
  background: radial-gradient(circle, rgba(255, 215, 140, 0.9) 0%, transparent 70%);
  border-radius: 50%;
  animation: particleFly 3s ease-out forwards;
}

@keyframes particleFly {
  0% { opacity: 0; transform: translate(0, 0) scale(0.5); }
  20% { opacity: 1; }
  100% { opacity: 0; transform: translate(-50px, 30px) scale(2); }
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
  .shofar-widget {
    right: 10px;
    bottom: -400px;
  }
  .shofar-widget.is-visible { bottom: -100px; }
  .shofar-widget.is-expanded { bottom: 15px; }
  
  .shofar-widget__inner {
    width: 240px;
    height: 360px;
  }
  
  .shofar-widget__scripture {
    top: 44px;
    left: 6px;
    width: 81px;
    height: 109px;
    padding: 6px;
  }
  
  .shofar-widget__title { font-size: 7px; }
  .shofar-widget__text { font-size: 7px; -webkit-line-clamp: 4; }
  .shofar-widget__ref { font-size: 6px; }
  .shofar-widget__glow { width: 50px; height: 50px; }
}

@media (max-width: 480px) {
  .shofar-widget__inner {
    width: 180px;
    height: 270px;
  }
  .shofar-widget__scripture {
    top: 33px;
    left: 4px;
    width: 61px;
    height: 82px;
    padding: 4px;
  }
  .shofar-widget__title { font-size: 5px; margin-bottom: 3px; }
  .shofar-widget__text { font-size: 5px; -webkit-line-clamp: 3; margin-bottom: 4px; }
  .shofar-widget__ref { font-size: 5px; }
}

/* ===== REDUCED MOTION ===== */
@media (prefers-reduced-motion: reduce) {
  .shofar-widget {
    transition: none;
    bottom: 10px;
  }
  .shofar-widget__glow,
  .shofar-particle {
    animation: none;
  }
  .shofar-widget__glow {
    opacity: 0.5;
  }
}

/* ===== ATTIRE FLUTTER (CSS fallback) ===== */
.shofar-widget__flutter-overlay {
  position: absolute;
  inset: 0;
  pointer-events: none;
  mix-blend-mode: soft-light;
  opacity: 0;
}

.shofar-widget.is-expanded .shofar-widget__flutter-overlay {
  opacity: 0.15;
  animation: flutterShimmer 4s ease-in-out infinite;
}

@keyframes flutterShimmer {
  0%, 100% { 
    background: linear-gradient(135deg, transparent 40%, rgba(255,255,255,0.3) 50%, transparent 60%);
    background-size: 200% 200%;
    background-position: 0% 0%;
  }
  50% { 
    background-position: 100% 100%;
  }
}
</style>

<!-- ============================================================
     SHOFAR SCRIPTURE WIDGET - HTML
     ============================================================ -->
<div class="shofar-widget" id="shofarWidget">
  <div class="shofar-widget__inner">
    
    <!-- Background Image -->
    <img src="<?php echo esc_url($image_url); ?>" 
         alt="Scripture of the Day" 
         class="shofar-widget__image"
         loading="lazy">
    
    <!-- Golden Glow at Horn Opening -->
    <div class="shofar-widget__glow"></div>
    
    <!-- Scripture Text on Banner -->
    <div class="shofar-widget__scripture">
      <div class="shofar-widget__title"><?php echo esc_html($scripture['title']); ?></div>
      <div class="shofar-widget__text"><?php echo esc_html($scripture['text']); ?></div>
      <div class="shofar-widget__ref"><?php echo esc_html($scripture['ref']); ?></div>
    </div>
    
    <!-- Flutter Shimmer Overlay -->
    <div class="shofar-widget__flutter-overlay"></div>
    
    <!-- Sound Particles Container -->
    <div class="shofar-widget__particles" id="shofarParticles"></div>
    
    <!-- Hint -->
    <div class="shofar-widget__hint">Today's Scripture</div>
    
    <!-- Close Button -->
    <button class="shofar-widget__close" id="shofarClose" title="Minimize">&times;</button>
    
  </div>
</div>

<!-- ============================================================
     SHOFAR SCRIPTURE WIDGET - JavaScript
     ============================================================ -->
<script id="shofar-widget-scripts">
(function() {
  'use strict';
  
  const HORN_X = <?php echo SHOFAR_HORN_X; ?>;
  const HORN_Y = <?php echo SHOFAR_HORN_Y; ?>;
  
  document.addEventListener('DOMContentLoaded', () => {
    const widget = document.getElementById('shofarWidget');
    const closeBtn = document.getElementById('shofarClose');
    const particlesContainer = document.getElementById('shofarParticles');
    
    if (!widget) {
      console.log('Shofar widget: element not found');
      return;
    }
    
    console.log('Shofar widget: initializing');
    
    // Check if already dismissed this session - check FIRST before scheduling timeout
    if (sessionStorage.getItem('shofar_dismissed') === 'true') {
      console.log('Shofar widget: dismissed this session');
      widget.style.display = 'none';
      return;
    }
    
    // Check reduced motion preference
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    
    // Slide up after page loads
    setTimeout(() => {
      console.log('Shofar widget: showing');
      widget.classList.add('is-visible');
    }, 1500);
    
    // Click to expand
    widget.addEventListener('click', (e) => {
      if (e.target === closeBtn || e.target.closest('.shofar-widget__close')) return;
      
      if (!widget.classList.contains('is-expanded')) {
        widget.classList.add('is-expanded');
        
        // Start particle effects when expanded
        if (!prefersReducedMotion) {
          startParticles();
        }
      }
    });
    
    // Close/Dismiss button - completely hides the widget
    closeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      widget.style.display = 'none';
      
      // Remember dismissal for this session
      sessionStorage.setItem('shofar_dismissed', 'true');
    });
    
    // Particle effect
    let particleInterval = null;
    
    function startParticles() {
      if (particleInterval) return;
      
      particleInterval = setInterval(() => {
        if (!widget.classList.contains('is-expanded')) {
          clearInterval(particleInterval);
          particleInterval = null;
          return;
        }
        createParticle();
      }, 400);
    }
    
    function createParticle() {
      const particle = document.createElement('div');
      particle.className = 'shofar-particle';
      
      // Position near horn opening
      const rect = widget.querySelector('.shofar-widget__inner').getBoundingClientRect();
      const startX = (HORN_X / 100) * rect.width;
      const startY = (HORN_Y / 100) * rect.height;
      
      particle.style.left = startX + (Math.random() - 0.5) * 20 + 'px';
      particle.style.top = startY + (Math.random() - 0.5) * 20 + 'px';
      
      particlesContainer.appendChild(particle);
      
      // Remove after animation
      setTimeout(() => particle.remove(), 3000);
    }
    
    // Store last viewed date
    const today = new Date().toDateString();
    const lastViewed = localStorage.getItem('shofar_scripture_viewed');
    if (lastViewed !== today) {
      localStorage.setItem('shofar_scripture_viewed', today);
    }
  });
})();
</script>

<?php
}, 100);
