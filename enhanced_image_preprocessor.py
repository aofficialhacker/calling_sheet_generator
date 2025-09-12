import cv2
import numpy as np
import pathlib
from PIL import Image, ImageEnhance
import json
import sys

class EnhancedImagePreprocessor:
    def __init__(self):
        self.debug_mode = False
    
    def detect_and_correct_skew(self, image):
        """Detect and correct document skew using Hough line detection"""
        try:
            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
            
            # Edge detection for line detection
            edges = cv2.Canny(gray, 50, 150, apertureSize=3)
            
            # Detect lines using Hough transform
            lines = cv2.HoughLines(edges, 1, np.pi/180, threshold=100)
            
            if lines is not None and len(lines) > 0:
                # Calculate average angle of detected lines
                angles = []
                for rho, theta in lines[:10]:  # Use first 10 lines
                    angle = theta - np.pi/2
                    angles.append(angle)
                
                # Use median angle to avoid outliers
                median_angle = np.median(angles)
                skew_angle = np.degrees(median_angle)
                
                # Only correct if skew is significant (> 0.5 degrees)
                if abs(skew_angle) > 0.5:
                    # Rotate image to correct skew
                    rows, cols = gray.shape
                    rotation_matrix = cv2.getRotationMatrix2D((cols/2, rows/2), skew_angle, 1)
                    corrected = cv2.warpAffine(image, rotation_matrix, (cols, rows), 
                                             flags=cv2.INTER_CUBIC, 
                                             borderMode=cv2.BORDER_REPLICATE)
                    return corrected, skew_angle
            
            return image, 0.0
        except Exception:
            return image, 0.0
    
    def enhance_contrast_and_brightness(self, image):
        """Enhanced contrast and brightness for better mark detection"""
        try:
            # Convert to PIL for better enhancement control
            if len(image.shape) == 3:
                pil_image = Image.fromarray(cv2.cvtColor(image, cv2.COLOR_BGR2RGB))
            else:
                pil_image = Image.fromarray(image)
            
            # Enhance contrast for handwritten marks
            contrast_enhancer = ImageEnhance.Contrast(pil_image)
            enhanced = contrast_enhancer.enhance(1.3)
            
            # Enhance sharpness for better edge detection
            sharpness_enhancer = ImageEnhance.Sharpness(enhanced)
            enhanced = sharpness_enhancer.enhance(1.2)
            
            # Convert back to CV2
            enhanced_cv = np.array(enhanced)
            if len(image.shape) == 3:
                enhanced_cv = cv2.cvtColor(enhanced_cv, cv2.COLOR_RGB2BGR)
            
            return enhanced_cv
        except Exception:
            return image
    
    def adaptive_noise_reduction(self, image):
        """Advanced noise reduction while preserving marks"""
        try:
            if len(image.shape) == 3:
                # For color images - reduce noise in each channel
                denoised = cv2.fastNlMeansDenoisingColored(image, None, 10, 10, 7, 21)
            else:
                # For grayscale - use stronger denoising
                denoised = cv2.fastNlMeansDenoising(image, None, h=12, templateWindowSize=7, searchWindowSize=21)
            
            return denoised
        except Exception:
            return image
    
    def enhance_handwritten_marks(self, gray_image):
        """Specifically enhance handwritten marks and filled circles"""
        try:
            # Create multiple enhanced versions for different mark types
            
            # 1. Enhance dark pen marks
            kernel_pen = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2, 2))
            pen_enhanced = cv2.morphologyEx(gray_image, cv2.MORPH_CLOSE, kernel_pen)
            
            # 2. Enhance filled circles using morphological operations
            kernel_circle = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3))
            circle_enhanced = cv2.morphologyEx(gray_image, cv2.MORPH_CLOSE, kernel_circle)
            
            # 3. Combine both enhancements
            combined = cv2.bitwise_and(pen_enhanced, circle_enhanced)
            
            return combined
        except Exception:
            return gray_image
    
    def create_multi_threshold_binary(self, gray_image):
        """Create multiple binary versions with different thresholds"""
        try:
            # Method 1: Adaptive Gaussian (current method)
            binary1 = cv2.adaptiveThreshold(
                gray_image, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, 
                cv2.THRESH_BINARY, 11, 2
            )
            
            # Method 2: Adaptive Mean (better for uniform lighting)
            binary2 = cv2.adaptiveThreshold(
                gray_image, 255, cv2.ADAPTIVE_THRESH_MEAN_C, 
                cv2.THRESH_BINARY, 11, 2
            )
            
            # Method 3: OTSU threshold (good for bimodal distribution)
            _, binary3 = cv2.threshold(gray_image, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
            
            # Combine the best of all three methods
            combined_binary = cv2.bitwise_and(binary1, cv2.bitwise_or(binary2, binary3))
            
            return combined_binary
        except Exception:
            # Fallback to original method
            return cv2.adaptiveThreshold(
                gray_image, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                cv2.THRESH_BINARY, 11, 2
            )
    
    def process_image(self, input_path: pathlib.Path) -> pathlib.Path:
        """Enhanced preprocessing pipeline for calling sheet OCR"""
        try:
            # Load original image
            image = cv2.imread(str(input_path))
            if image is None:
                return input_path
            
            # Step 1: Correct perspective/skew
            corrected, skew_angle = self.detect_and_correct_skew(image)
            if self.debug_mode:
                print(f"Skew correction applied: {skew_angle:.2f} degrees", file=sys.stderr)
            
            # Step 2: Enhance contrast and brightness
            enhanced = self.enhance_contrast_and_brightness(corrected)
            
            # Step 3: Advanced noise reduction
            denoised = self.adaptive_noise_reduction(enhanced)
            
            # Step 4: Convert to grayscale
            gray = cv2.cvtColor(denoised, cv2.COLOR_BGR2GRAY)
            
            # Step 5: Enhance handwritten marks specifically
            mark_enhanced = self.enhance_handwritten_marks(gray)
            
            # Step 6: Create optimized binary image
            binary_image = self.create_multi_threshold_binary(mark_enhanced)
            
            # Step 7: Final cleanup - remove small noise
            kernel_cleanup = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (1, 1))
            cleaned = cv2.morphologyEx(binary_image, cv2.MORPH_OPEN, kernel_cleanup)
            
            # Save enhanced image
            output_path = input_path.parent / f"enhanced_{input_path.name}"
            cv2.imwrite(str(output_path), cleaned)
            
            if self.debug_mode:
                # Save intermediate steps for debugging
                debug_path = input_path.parent / f"debug_steps_{input_path.stem}"
                debug_path.mkdir(exist_ok=True)
                cv2.imwrite(str(debug_path / "01_corrected.jpg"), corrected)
                cv2.imwrite(str(debug_path / "02_enhanced.jpg"), enhanced)
                cv2.imwrite(str(debug_path / "03_denoised.jpg"), denoised)
                cv2.imwrite(str(debug_path / "04_mark_enhanced.jpg"), mark_enhanced)
                cv2.imwrite(str(debug_path / "05_final_binary.jpg"), cleaned)
            
            return output_path
            
        except Exception as e:
            if self.debug_mode:
                print(f"Enhancement failed: {str(e)}", file=sys.stderr)
            return input_path

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "No file path provided"}))
        sys.exit(1)
    
    input_path = pathlib.Path(sys.argv[1])
    if not input_path.exists():
        print(json.dumps({"error": f"File not found: {input_path}"}))
        sys.exit(1)
    
    # Enable debug mode if requested
    debug_mode = len(sys.argv) > 2 and sys.argv[2] == "debug"
    
    processor = EnhancedImagePreprocessor()
    processor.debug_mode = debug_mode
    
    try:
        enhanced_path = processor.process_image(input_path)
        print(json.dumps({
            "success": True,
            "enhanced_image_path": str(enhanced_path),
            "original_path": str(input_path)
        }))
    except Exception as e:
        print(json.dumps({"error": f"Processing failed: {str(e)}"}))
        sys.exit(1)

if __name__ == "__main__":
    main()