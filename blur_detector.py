import sys
import json
import cv2
import numpy as np
import pathlib

def calculate_blur_variance(image_path: str) -> float:
    """
    Calculate the variance of Laplacian to detect blur.
    Lower values indicate more blur.
    Typical thresholds:
    - > 100: Sharp image
    - 50-100: Slightly blurry but acceptable
    - < 50: Too blurry
    """
    try:
        image = cv2.imread(image_path)
        if image is None:
            return 0.0
        
        # Convert to grayscale
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        
        # Calculate the Laplacian variance
        laplacian = cv2.Laplacian(gray, cv2.CV_64F)
        variance = laplacian.var()
        
        return float(variance)
    except Exception as e:
        print(json.dumps({"error": f"Error calculating blur: {str(e)}"}))
        return 0.0

def calculate_blur_fft(image_path: str) -> float:
    """
    Alternative blur detection using FFT magnitude in frequency domain.
    Higher values indicate sharper images.
    """
    try:
        image = cv2.imread(image_path, 0)  # Read as grayscale
        if image is None:
            return 0.0
        
        # Apply FFT
        f_transform = np.fft.fft2(image)
        f_shift = np.fft.fftshift(f_transform)
        magnitude = np.abs(f_shift)
        
        # Calculate the mean of high frequency components
        rows, cols = image.shape
        center_row, center_col = rows // 2, cols // 2
        
        # Create a mask to extract high frequency components
        mask = np.zeros((rows, cols), np.uint8)
        mask[center_row-30:center_row+30, center_col-30:center_col+30] = 1
        
        # Apply mask and calculate mean of high frequencies
        high_freq = magnitude * (1 - mask)
        blur_score = np.mean(high_freq)
        
        return float(blur_score)
    except Exception as e:
        print(json.dumps({"error": f"Error calculating FFT blur: {str(e)}"}))
        return 0.0

def is_image_blurry(image_path: str, variance_threshold: float = 50.0) -> dict:
    """
    Determine if an image is too blurry using multiple methods.
    
    Args:
        image_path: Path to the image file
        variance_threshold: Threshold for Laplacian variance (default: 50.0)
    
    Returns:
        dict with blur analysis results
    """
    try:
        # Calculate blur metrics
        laplacian_variance = calculate_blur_variance(image_path)
        fft_score = calculate_blur_fft(image_path)
        
        # Determine if blurry
        is_blurry = laplacian_variance < variance_threshold
        
        # Quality assessment
        if laplacian_variance > 100:
            quality = "excellent"
        elif laplacian_variance > 80:
            quality = "good"
        elif laplacian_variance > 50:
            quality = "acceptable"
        elif laplacian_variance > 20:
            quality = "poor"
        else:
            quality = "very_poor"
        
        return {
            "is_blurry": is_blurry,
            "laplacian_variance": round(laplacian_variance, 2),
            "fft_score": round(fft_score, 2),
            "quality": quality,
            "threshold_used": variance_threshold,
            "recommendation": "retake" if is_blurry else "acceptable"
        }
        
    except Exception as e:
        return {
            "error": f"Blur detection failed: {str(e)}",
            "is_blurry": True,  # Assume blurry on error for safety
            "recommendation": "retake"
        }

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "No file path provided"}))
        sys.exit(1)
    
    image_path = sys.argv[1]
    
    # Optional threshold parameter
    threshold = float(sys.argv[2]) if len(sys.argv) > 2 else 50.0
    
    # Check if file exists
    if not pathlib.Path(image_path).exists():
        print(json.dumps({"error": f"File not found: {image_path}"}))
        sys.exit(1)
    
    # Perform blur detection
    result = is_image_blurry(image_path, threshold)
    
    # Output result as JSON
    print(json.dumps(result))

if __name__ == "__main__":
    main()